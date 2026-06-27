<?php

namespace Tests\Feature\Banner;

use App\Features\Banner\Actions\DeleteBannerImage;
use App\Features\Banner\Actions\StoreBannerImage;
use App\Features\Banner\Actions\UpdateBannerImage;
use App\Files\FileStorage;
use App\Models\Banner;
use App\Models\BannerImage;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class BannerImageActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_a_public_file_links_it_and_associates_placements(): void
    {
        $banner = Banner::create(['name' => 'top_after']);

        $image = app(StoreBannerImage::class)(
            UploadedFile::fake()->image('b.png', 20, 20),
            'https://ad.example.test',
            'Promo',
            [$banner->getKey()],
        );

        $file = $image->file;
        $this->assertNotNull($file);
        $this->assertSame('bannerImage', $file->related_entity_type);
        $this->assertSame($image->getKey(), $file->related_entity_id);
        $this->assertSame(1, DB::table('file_bin')->where('file_id', $file->getKey())->count());
        $this->assertTrue($image->banners->contains($banner));
        $this->assertSame('https://ad.example.test', $image->url);

        // Public: a guest can fetch the bytes through the banner delivery route, served inline as the
        // raster image type.
        $response = $this->get(route('banner.image', $file->name));
        $response->assertOk();
        $this->assertStringContainsString('image/', (string) $response->headers->get('Content-Type'));
    }

    public function test_a_non_raster_banner_file_is_served_as_an_attachment(): void
    {
        // The Filament upload rejects non-raster types, but a future OpenPNE 3 banner-image upgrade may
        // import one; it must be an attachment, never an inline same-origin document.
        $image = app(StoreBannerImage::class)(UploadedFile::fake()->create('x.html', 1, 'text/html'), null, null, []);

        $response = $this->get(route('banner.image', $image->file->name));

        $response->assertOk();
        $this->assertStringContainsString('application/octet-stream', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_update_changes_metadata_and_placements(): void
    {
        $before = Banner::create(['name' => 'top_before']);
        $after = Banner::create(['name' => 'top_after']);
        $image = app(StoreBannerImage::class)(UploadedFile::fake()->image('x.png', 10, 10), 'https://old.test', 'Old', [$before->getKey()]);

        app(UpdateBannerImage::class)($image, 'https://new.test', 'New', [$after->getKey()], null);

        $fresh = $image->fresh();
        $this->assertSame('https://new.test', $fresh->url);
        $this->assertSame('New', $fresh->name);
        $this->assertEqualsCanonicalizing([$after->getKey()], $fresh->banners->pluck('id')->all());
    }

    public function test_update_with_null_placements_leaves_them_untouched(): void
    {
        // The image edit form no longer manages placements (they are chosen on the Banner page), so a
        // null placements argument must preserve the existing associations.
        $banner = Banner::create(['name' => 'top_before']);
        $image = app(StoreBannerImage::class)(UploadedFile::fake()->image('x.png', 10, 10), 'https://old.test', 'Old', [$banner->getKey()]);

        app(UpdateBannerImage::class)($image, 'https://new.test', 'New', null, null);

        $fresh = $image->fresh();
        $this->assertSame('https://new.test', $fresh->url);
        $this->assertEqualsCanonicalizing([$banner->getKey()], $fresh->banners->pluck('id')->all());
    }

    public function test_update_swaps_the_file_and_purges_the_old_one(): void
    {
        $image = app(StoreBannerImage::class)(UploadedFile::fake()->image('one.png', 10, 10), null, null, []);
        $old = $image->file;

        app(UpdateBannerImage::class)($image, null, null, [], UploadedFile::fake()->image('two.png', 10, 10));

        $new = $image->fresh()->file;
        $this->assertNotSame($old->getKey(), $new->getKey());
        $this->assertSame('bannerImage', $new->related_entity_type);
        $this->assertSame($image->getKey(), $new->related_entity_id);

        $this->assertNull(File::find($old->getKey()));
        $this->assertSame(0, DB::table('file_bin')->where('file_id', $old->getKey())->count());
    }

    public function test_a_failed_image_swap_rolls_back_the_metadata_too(): void
    {
        $banner = Banner::create(['name' => 'top_after']);
        $image = app(StoreBannerImage::class)(UploadedFile::fake()->image('old.png', 10, 10), 'https://old.test', 'Old', [$banner->getKey()]);
        $oldFileId = $image->file_id;

        // The replacement byte write fails; the whole edit (link/label included) must roll back.
        $this->mock(FileStorage::class, function ($mock): void {
            $mock->shouldReceive('writeStream')->andThrow(new RuntimeException('storage down'));
            $mock->shouldReceive('delete');
        });

        try {
            app(UpdateBannerImage::class)($image, 'https://new.test', 'New', [$banner->getKey()], UploadedFile::fake()->image('new.png', 10, 10));
            $this->fail('expected the failed store to throw');
        } catch (Throwable) {
            // expected
        }

        $fresh = $image->fresh();
        $this->assertSame('https://old.test', $fresh->url);
        $this->assertSame('Old', $fresh->name);
        $this->assertSame($oldFileId, $fresh->file_id);
        $this->assertNotNull(File::find($oldFileId));
    }

    public function test_delete_removes_the_row_its_placements_and_the_file(): void
    {
        $banner = Banner::create(['name' => 'top_after']);
        $image = app(StoreBannerImage::class)(UploadedFile::fake()->image('b.png', 10, 10), null, null, [$banner->getKey()]);
        $fileId = $image->file_id;

        app(DeleteBannerImage::class)($image);

        $this->assertNull(BannerImage::find($image->getKey()));
        $this->assertSame(0, DB::table('banner_use_images')->where('banner_image_id', $image->getKey())->count());
        $this->assertNull(File::find($fileId));
        $this->assertSame(0, DB::table('file_bin')->where('file_id', $fileId)->count());
    }

    public function test_a_failure_after_the_bytes_are_stored_does_not_orphan_them(): void
    {
        // writeStream succeeds (bytes land), then the placement sync hits a missing banner id and the
        // transaction rolls back; the compensating wrapper must purge the stored bytes.
        $this->mock(FileStorage::class, function ($mock): void {
            $mock->shouldReceive('writeStream')->once();
            $mock->shouldReceive('delete')->once();
        });

        try {
            app(StoreBannerImage::class)(UploadedFile::fake()->image('b.png', 10, 10), null, null, [999999]);
            $this->fail('expected the bad placement to throw');
        } catch (Throwable) {
            // expected
        }

        $this->assertSame(0, DB::table('banner_images')->count());
        $this->assertSame(0, DB::table('files')->count());
    }
}
