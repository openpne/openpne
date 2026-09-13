<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Files\FileUploader;
use App\Files\ImageProcessor;
use App\Files\ImageTransform;
use App\Models\File;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Runs against a live sidecar, like the imgproxy contract test; elsewhere every case is skipped. */
class HeicUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (env('OPENPNE_IMAGE_PROCESSOR') !== 'imgproxy') {
            $this->markTestSkipped('OPENPNE_IMAGE_PROCESSOR=imgproxy, with a sidecar to talk to, runs this.');
        }

        config(['openpne.images.processor' => 'imgproxy']);
        $this->app->forgetInstance(ImageProcessor::class);
        Storage::fake('image_cache');
    }

    public function test_a_heic_upload_is_stored_as_a_jpeg_and_served_clean_and_upright(): void
    {
        $owner = Member::factory()->create();

        $file = $this->upload($owner, 'IMG_0001.heic', 'heic-gps-orientation.heic');

        $this->assertSame('image/jpeg', $file->type);
        $this->assertSame('IMG_0001.heic', $file->original_filename);
        $this->assertSame([6, 12], [$file->width, $file->height]);
        $this->assertFalse($file->animated);

        $response = $this->actingAs($owner)->get($file->url())->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringNotContainsString('2021:07:04', $response->getContent());
        $this->actingAs($owner)->get($file->thumbnailUrl(120, 120, square: true))->assertOk();
    }

    public function test_a_heic_row_is_regenerated_from_its_stored_bytes_after_the_canonical_is_lost(): void
    {
        // The row's type is the canonical's, so the regeneration hands the sidecar HEIC bytes labelled
        // image/jpeg: the mime is advisory and the sidecar reads the container itself.
        $owner = Member::factory()->create();
        $file = $this->upload($owner, 'IMG_0001.heic', 'heic-gps-orientation.heic');
        Storage::disk('image_cache')->deleteDirectory($file->name);

        $response = $this->actingAs($owner)->get($file->url())->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($response->getContent())[2]);
        $this->assertStringNotContainsString('2021:07:04', $response->getContent());
        Storage::disk('image_cache')->assertExists(ImageTransform::raw()->cacheKey($file->name, 'jpg'));
    }

    public function test_an_avif_upload_is_stored_as_a_webp(): void
    {
        $owner = Member::factory()->create();

        $file = $this->upload($owner, 'photo.avif', 'avif-copyright.avif');

        $this->assertSame('image/webp', $file->type);
        $response = $this->actingAs($owner)->get($file->url())->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->assertStringNotContainsString('COPYRIGHT-LEAK', $response->getContent());
        $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring($response->getContent())[2]);
    }

    private function upload(Member $owner, string $name, string $fixture): File
    {
        return app(FileUploader::class)->store(
            UploadedFile::fake()->createWithContent($name, (string) file_get_contents(base_path('tests/Fixtures/images/'.$fixture))),
            'member',
            (int) $owner->getKey(),
        );
    }
}
