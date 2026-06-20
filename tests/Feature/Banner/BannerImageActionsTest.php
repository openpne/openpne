<?php

namespace Tests\Feature\Banner;

use App\Features\Banner\Actions\DeleteBannerImage;
use App\Features\Banner\Actions\ReplaceBannerImage;
use App\Features\Banner\Actions\StoreBannerImage;
use App\Files\FileStorage;
use App\Models\Banner;
use App\Models\BannerImage;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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

        // Public: a guest can fetch the bytes through the banner delivery route.
        $this->get(route('banner.image', $file->name))->assertOk();
    }

    public function test_replace_swaps_the_file_and_purges_the_old_one(): void
    {
        $image = app(StoreBannerImage::class)(UploadedFile::fake()->image('one.png', 10, 10), null, null, []);
        $old = $image->file;

        app(ReplaceBannerImage::class)($image, UploadedFile::fake()->image('two.png', 10, 10));

        $new = $image->fresh()->file;
        $this->assertNotSame($old->getKey(), $new->getKey());
        $this->assertSame('bannerImage', $new->related_entity_type);
        $this->assertSame($image->getKey(), $new->related_entity_id);

        $this->assertNull(File::find($old->getKey()));
        $this->assertSame(0, DB::table('file_bin')->where('file_id', $old->getKey())->count());
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
