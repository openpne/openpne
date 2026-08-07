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
use Intervention\Gif\Builder;
use Intervention\Gif\Decoder;
use Intervention\Image\ImageManager;
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

    public function test_a_guest_gets_an_avatar_at_both_thumbnail_and_original_size(): void
    {
        // The route carries no login of its own — FilePolicy is the whole gate. Both geometries are
        // pinned: the thumbnail is what a page embeds, w_h is the full-size variant of the same URL.
        $file = $this->avatar(Member::factory()->create());

        $this->get($file->thumbnailUrl(120, 120, square: true))->assertOk();
        $this->get($this->url($file, 'w_h'))->assertOk();
    }

    public function test_a_guest_is_denied_a_thumbnail_the_policy_does_not_open(): void
    {
        $file = File::factory()->create([
            'type' => 'image/png',
            'related_entity_type' => null,
            'related_entity_id' => null,
        ]);

        $this->get($this->url($file, 'w120_h120_sq'))->assertNotFound();
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

    public function test_an_animated_gif_is_thumbnailed_to_a_single_still_frame(): void
    {
        $owner = Member::factory()->create();
        $file = $this->animatedGif($owner);

        $response = $this->actingAs($owner)->get($file->thumbnailUrl(120, 120));

        $response->assertOk();
        $this->assertSame(1, $this->frameCount($response->getContent()));
        // Not pinned to 120x120: GD reports a GIF at the size of its first frame's
        // descriptor rather than the logical screen, so the box it fits into is
        // driver-dependent — as it was in OpenPNE 3, which decoded the same way.
        $this->assertLessThanOrEqual([120, 120], $this->dimensions($response->getContent()));
    }

    public function test_the_original_size_keeps_an_animated_gif_intact(): void
    {
        $owner = Member::factory()->create();
        $file = $this->animatedGif($owner);

        $response = $this->actingAs($owner)->get($this->url($file, 'w_h', 'gif'));

        $response->assertOk();
        $this->assertSame(3, $this->frameCount($response->getContent()));
    }

    public function test_the_imagick_driver_also_thumbnails_an_animated_gif_to_a_still(): void
    {
        // Imagick cannot be told to skip the frames while decoding (intervention/image 4.2.0
        // fails the decode outright when configured that way), so it takes the other route
        // through StillImageDecoder. Both have to end at one frame.
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('ext-imagick is not installed.');
        }

        config(['openpne.images.driver' => 'imagick']);
        $this->app->forgetInstance(ImageManager::class);

        $owner = Member::factory()->create();
        $file = $this->animatedGif($owner);

        $response = $this->actingAs($owner)->get($file->thumbnailUrl(120, 120));

        $response->assertOk();
        $this->assertSame(1, $this->frameCount($response->getContent()));
    }

    public function test_a_variant_cached_by_an_earlier_generation_is_not_served(): void
    {
        // The cache disk outlives a release, and a variant is only regenerated on a miss, so a
        // thumbnail cached before a change to the pipeline would otherwise be served forever.
        $owner = Member::factory()->create();
        $file = $this->animatedGif($owner);
        Storage::disk('image_cache')->put("{$file->name}/w120_h120.gif", 'stale bytes');

        $response = $this->actingAs($owner)->get($file->thumbnailUrl(120, 120));

        $response->assertOk();
        $this->assertNotSame('stale bytes', $response->getContent());
        $this->assertSame(1, $this->frameCount($response->getContent()));
    }

    public function test_migrated_openpne3_names_with_underscores_or_dots_are_served(): void
    {
        // Migrated OpenPNE 3 files keep their original name verbatim. OpenPNE 3 allowed
        // [\w._-], so a name may carry underscores (m_42_abcdef_jpg) or dots (test1.jpg);
        // both must route to delivery.
        $owner = Member::factory()->create();

        foreach (['m_42_abcdef_jpg', 'test1.jpg'] as $name) {
            $file = $this->imageFileNamed($owner, $name);

            $this->actingAs($owner)->get($file->thumbnailUrl(120, 120, square: true))
                ->assertOk();
        }
    }

    private function imageFileNamed(Member $owner, string $name): File
    {
        $file = File::factory()->create([
            'name' => $name,
            'type' => 'image/jpeg',
            'related_entity_type' => 'member',
            'related_entity_id' => $owner->getKey(),
        ]);

        $upload = UploadedFile::fake()->image('a.jpg', 240, 120);
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, (string) file_get_contents($upload->getRealPath()));
        rewind($stream);
        app(FileStorage::class)->writeStream($file, $stream);
        fclose($stream);

        return $file;
    }

    private function avatar(Member $owner, int $width = 240, int $height = 120): File
    {
        return app(FileUploader::class)->store(
            UploadedFile::fake()->image('a.png', $width, $height),
            'member',
            (int) $owner->getKey(),
        );
    }

    private function url(File $file, string $geometry, string $format = 'png'): string
    {
        return route('image.show', ['format' => $format, 'geometry' => $geometry, 'name' => $file->name, 'ext' => $format]);
    }

    /**
     * A three-frame GIF whose first frame is smaller than the logical screen, as an
     * optimised GIF's is: the frames after it are deltas placed at an offset, which is
     * what a thumbnail must not try to compose.
     */
    private function animatedGif(Member $owner): File
    {
        $builder = Builder::canvas(400, 400);
        $builder->addFrame(source: self::gifFrame(320, 10), delay: 0.1, left: 40, top: 40);
        $builder->addFrame(source: self::gifFrame(60, 120), delay: 0.1, left: 200, top: 300);
        $builder->addFrame(source: self::gifFrame(60, 240), delay: 0.1, left: 210, top: 300);
        $builder->setLoops(0);

        return app(FileUploader::class)->store(
            UploadedFile::fake()->createWithContent('a.gif', $builder->encode()),
            'member',
            (int) $owner->getKey(),
        );
    }

    /** One frame as a standalone GIF, the form Builder::addFrame() takes. */
    private static function gifFrame(int $side, int $shade): string
    {
        $gd = imagecreate($side, $side);
        imagecolorallocate($gd, $shade, 40, 200);

        ob_start();
        imagegif($gd);

        return (string) ob_get_clean();
    }

    private function frameCount(string $bytes): int
    {
        return count(Decoder::decode($bytes)->frames());
    }

    /** @return array{0: int, 1: int} */
    private function dimensions(string $bytes): array
    {
        $size = getimagesizefromstring($bytes);

        return [$size[0], $size[1]];
    }
}
