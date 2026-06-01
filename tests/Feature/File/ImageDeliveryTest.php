<?php

namespace Tests\Feature\File;

use App\Files\FileStorage;
use App\Files\FileUploader;
use App\Files\ImageTransform;
use App\Models\File;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Thumbnail delivery at the OpenPNE 3-compatible /cache/img URL: gated by FilePolicy,
 * size-whitelisted, cached, and purged with the file.
 */
class ImageDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('image_cache');
    }

    public function test_a_square_thumbnail_is_centre_cropped_to_the_exact_box(): void
    {
        $owner = Member::factory()->create();
        $file = $this->avatar($owner, 240, 120);

        $response = $this->actingAs($owner)->get($file->thumbnailUrl(120, 120, square: true));

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame([120, 120], $this->dimensions($response->getContent()));
    }

    public function test_a_fit_thumbnail_preserves_aspect_ratio_without_upscaling(): void
    {
        $owner = Member::factory()->create();
        $file = $this->avatar($owner, 240, 120);

        $response = $this->actingAs($owner)->get($file->thumbnailUrl(120, 120));

        $response->assertOk();
        $this->assertSame([120, 60], $this->dimensions($response->getContent()));
    }

    public function test_the_original_size_returns_the_source_dimensions(): void
    {
        $owner = Member::factory()->create();
        $file = $this->avatar($owner, 240, 120);

        $response = $this->actingAs($owner)->get($this->url($file, 'w_h'));

        $response->assertOk();
        $this->assertSame([240, 120], $this->dimensions($response->getContent()));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $file = $this->avatar(Member::factory()->create());

        $this->get($file->thumbnailUrl(120, 120, square: true))->assertRedirect(route('login'));
    }

    public function test_a_blocked_member_gets_404(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $owner->blocksMade()->attach($viewer, ['created_at' => now()]);
        $file = $this->avatar($owner);

        $this->actingAs($viewer)->get($file->thumbnailUrl(120, 120, square: true))->assertNotFound();
    }

    public function test_a_non_whitelisted_size_is_rejected(): void
    {
        $owner = Member::factory()->create();
        $file = $this->avatar($owner);

        $this->actingAs($owner)->get($this->url($file, 'w999_h999'))->assertNotFound();
    }

    public function test_a_format_that_is_not_the_files_own_is_rejected(): void
    {
        $owner = Member::factory()->create();
        $file = $this->avatar($owner); // png

        $url = route('image.show', ['format' => 'jpg', 'geometry' => 'w120_h120', 'name' => $file->name, 'ext' => 'jpg']);
        $this->actingAs($owner)->get($url)->assertNotFound();
    }

    public function test_the_thumbnail_is_cached_then_purged_when_the_file_is_deleted(): void
    {
        $owner = Member::factory()->create();
        $file = $this->avatar($owner);
        $key = ImageTransform::fromGeometry('w120_h120_sq')->cacheKey($file->name, 'png');

        $this->actingAs($owner)->get($file->thumbnailUrl(120, 120, square: true))->assertOk();
        Storage::disk('image_cache')->assertExists($key);

        $file->delete();
        Storage::disk('image_cache')->assertMissing($key);
    }

    public function test_a_migrated_openpne3_style_underscore_name_is_served(): void
    {
        // Migrated OpenPNE 3 files keep their original name verbatim, which carries
        // underscores (e.g. m_42_abcdef_jpg). The route must accept them.
        $owner = Member::factory()->create();
        $file = File::factory()->create([
            'name' => 'm_42_abcdef_jpg',
            'type' => 'image/jpeg',
            'related_entity_type' => 'member',
            'related_entity_id' => $owner->getKey(),
        ]);
        $upload = UploadedFile::fake()->image('a.jpg', 240, 120);
        $bytes = (string) file_get_contents($upload->getRealPath());
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);
        app(FileStorage::class)->writeStream($file, $stream);
        fclose($stream);

        $this->actingAs($owner)->get($file->thumbnailUrl(120, 120, square: true))->assertOk();
    }

    private function avatar(Member $owner, int $width = 240, int $height = 120): File
    {
        return app(FileUploader::class)->store(
            UploadedFile::fake()->image('a.png', $width, $height),
            'member',
            (int) $owner->getKey(),
        );
    }

    private function url(File $file, string $geometry): string
    {
        return route('image.show', ['format' => 'png', 'geometry' => $geometry, 'name' => $file->name, 'ext' => 'png']);
    }

    /** @return array{0: int, 1: int} */
    private function dimensions(string $bytes): array
    {
        $size = getimagesizefromstring($bytes);

        return [$size[0], $size[1]];
    }
}
