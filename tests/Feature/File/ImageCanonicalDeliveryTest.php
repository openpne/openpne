<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Files\FileStorage;
use App\Files\FileUploader;
use App\Files\ImageProcessor;
use App\Files\ImageProcessorUnavailableException;
use App\Files\ImageSpec;
use App\Files\ImageTransform;
use App\Files\ProcessedImage;
use App\Models\AdminUser;
use App\Models\BannerImage;
use App\Models\File;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Every inline raster route answers the canonical; the stored bytes reach only the admin raw route
 * and non-raster attachments (docs/internals/security.md, "Inline delivery is re-encoded").
 */
class ImageCanonicalDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const GPS_SENTINEL = '2021:07:04';

    public function test_the_original_route_serves_the_canonical_not_the_uploaded_bytes(): void
    {
        $owner = Member::factory()->create();
        $file = $this->uploaded($owner, 'jpeg-gps-orientation.jpg');

        $response = $this->actingAs($owner)->get($file->url())->assertOk();

        $this->assertCleanAndUpright($response, $file, 'jpg');
        $this->assertSame('private, max-age=0, must-revalidate', $this->cacheControl($response));
    }

    public function test_the_raw_geometry_serves_the_same_canonical(): void
    {
        $owner = Member::factory()->create();
        $file = $this->uploaded($owner, 'jpeg-gps-orientation.jpg');

        $response = $this->actingAs($owner)
            ->get(route('image.show', ['format' => 'jpg', 'geometry' => 'w_h', 'name' => $file->name, 'ext' => 'jpg']))
            ->assertOk();

        $this->assertStringNotContainsString(self::GPS_SENTINEL, $response->getContent());
        $this->assertSame(ImageTransform::raw()->etag($file->name, 'jpg'), $response->headers->get('ETag'));
        $this->assertSame($this->actingAs($owner)->get($file->url())->getContent(), $response->getContent());
    }

    public function test_an_imported_row_is_answered_from_a_canonical_made_on_first_view(): void
    {
        // Stored bytes written straight to storage with their EXIF intact, as the upgrade leaves them.
        $owner = Member::factory()->create();
        $file = $this->stored('image/jpeg', $this->fixture('jpeg-gps-orientation.jpg'), ['related_entity_type' => 'member', 'related_entity_id' => $owner->getKey()]);
        Storage::disk('image_cache')->assertMissing(ImageTransform::raw()->cacheKey($file->name, 'jpg'));

        $response = $this->actingAs($owner)->get($file->url())->assertOk();

        $this->assertCleanAndUpright($response, $file, 'jpg');
        Storage::disk('image_cache')->assertExists(ImageTransform::raw()->cacheKey($file->name, 'jpg'));
    }

    public function test_the_public_and_banner_routes_serve_the_canonical_too(): void
    {
        $public = $this->stored('image/jpeg', $this->fixture('jpeg-gps-orientation.jpg'), ['explicit_visibility' => File::VISIBILITY_PUBLIC]);
        $banner = $this->stored('image/jpeg', $this->fixture('jpeg-gps-orientation.jpg'), ['related_entity_type' => 'bannerImage', 'related_entity_id' => BannerImage::factory()->create()->getKey()]);

        $this->assertCleanAndUpright($this->get(route('file.public', $public->name))->assertOk(), $public, 'jpg');
        $this->assertCleanAndUpright($this->get(route('banner.image', $banner->name))->assertOk(), $banner, 'jpg');
    }

    public function test_the_admin_raw_route_still_streams_the_stored_bytes(): void
    {
        $file = $this->stored('image/jpeg', $this->fixture('jpeg-gps-orientation.jpg'), ['related_entity_type' => 'member', 'related_entity_id' => Member::factory()->create()->getKey()]);

        $response = $this->actingAs(AdminUser::factory()->create(), 'admin')
            ->get(route('admin.file.raw', ['file' => $file->name]))
            ->assertOk();

        $this->assertStringContainsString(self::GPS_SENTINEL, $response->streamedContent());
    }

    public function test_a_non_raster_attachment_keeps_the_stored_bytes_and_its_name(): void
    {
        $owner = Member::factory()->create();
        $file = $this->stored('application/pdf', '%PDF-1.4 stored as is', ['related_entity_type' => 'member', 'related_entity_id' => $owner->getKey(), 'original_filename' => 'notes.pdf']);

        $response = $this->actingAs($owner)->get($file->url())->assertOk();

        $this->assertSame('%PDF-1.4 stored as is', $response->streamedContent());
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('notes.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('"'.$file->name.'"', $response->headers->get('ETag'));
    }

    public function test_a_refused_row_is_not_found_on_the_original_route(): void
    {
        $owner = Member::factory()->create();
        $file = $this->stored('image/png', 'not an image at all', ['related_entity_type' => 'member', 'related_entity_id' => $owner->getKey()]);

        $this->actingAs($owner)->get($file->url())->assertNotFound();
    }

    public function test_a_processor_outage_is_503_with_a_retry_hint_on_every_inline_route(): void
    {
        $owner = Member::factory()->create();
        $file = $this->stored('image/jpeg', $this->fixture('jpeg-gps-orientation.jpg'), ['related_entity_type' => 'member', 'related_entity_id' => $owner->getKey()]);
        $banner = $this->stored('image/jpeg', $this->fixture('jpeg-gps-orientation.jpg'), ['related_entity_type' => 'bannerImage', 'related_entity_id' => BannerImage::factory()->create()->getKey()]);
        $this->app->instance(ImageProcessor::class, new class implements ImageProcessor
        {
            public function process(string $bytes, string $mime, ImageSpec $spec): ProcessedImage
            {
                throw new ImageProcessorUnavailableException('imgproxy did not answer');
            }

            public function preservesAnimation(): bool
            {
                return false;
            }
        });

        $this->actingAs($owner)->get($file->url())->assertStatus(503)->assertHeader('Retry-After', '30');
        $this->get(route('banner.image', $banner->name))->assertStatus(503)->assertHeader('Retry-After', '30');
    }

    public function test_a_matching_canonical_tag_is_answered_304_without_reading_anything(): void
    {
        $owner = Member::factory()->create();
        $file = $this->uploaded($owner, 'jpeg-gps-orientation.jpg');
        $etag = (string) $this->actingAs($owner)->get($file->url())->assertOk()->headers->get('ETag');

        // With the canonical gone too, a 200 would have to regenerate it: nothing is read, nothing is made.
        Storage::disk('image_cache')->deleteDirectory($file->name);
        $this->mock(FileStorage::class)->shouldNotReceive('readStream');
        $this->withHeader('If-None-Match', $etag)->get($file->url())->assertStatus(304);
        Storage::disk('image_cache')->assertMissing(ImageTransform::raw()->cacheKey($file->name, 'jpg'));
    }

    private function assertCleanAndUpright(TestResponse $response, File $file, string $format): void
    {
        $body = (string) $response->getContent();

        $this->assertStringNotContainsString(self::GPS_SENTINEL, $body);
        $this->assertStringNotContainsString('LEAK', $body);
        $this->assertSame(extension_loaded('exif') ? [6, 12] : [12, 6], array_slice((array) getimagesizefromstring($body), 0, 2));
        $this->assertSame((string) strlen($body), $response->headers->get('Content-Length'));
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame("inline; filename={$file->name}.{$format}", $response->headers->get('Content-Disposition'));
        $this->assertSame(ImageTransform::raw()->etag($file->name, $format), $response->headers->get('ETag'));
    }

    private function cacheControl(TestResponse $response): string
    {
        return implode(', ', array_map(
            fn (string $d): string => $response->headers->getCacheControlDirective($d) === true ? $d : "{$d}=".$response->headers->getCacheControlDirective($d),
            array_filter(['private', 'max-age', 'must-revalidate'], fn (string $d): bool => $response->headers->hasCacheControlDirective($d)),
        ));
    }

    private function uploaded(Member $owner, string $fixture): File
    {
        return app(FileUploader::class)->store(
            UploadedFile::fake()->createWithContent('photo.jpg', $this->fixture($fixture)),
            'member',
            (int) $owner->getKey(),
        );
    }

    /** @param  array<string, mixed>  $attributes */
    private function stored(string $type, string $bytes, array $attributes): File
    {
        $file = File::factory()->create($attributes + ['type' => $type, 'byte_size' => strlen($bytes)]);

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);
        app(FileStorage::class)->writeStream($file, $stream);
        fclose($stream);

        return $file;
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/images/{$name}"));
    }
}
