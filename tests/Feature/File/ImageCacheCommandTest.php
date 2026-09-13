<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Files\FileStorage;
use App\Files\FileUploader;
use App\Files\ImageCache;
use App\Files\ImageProcessor;
use App\Files\ImageProcessorUnavailableException;
use App\Files\ImageSpec;
use App\Files\ImageTransform;
use App\Files\ProcessedImage;
use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageBytes;
use Tests\TestCase;

class ImageCacheCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_counts_warm_cold_and_refused_pictures_and_names_the_refused(): void
    {
        $warm = app(FileUploader::class)->store(UploadedFile::fake()->image('a.png', 8, 8));
        $cold = $this->stored('image/png', ImageBytes::png());
        $refused = $this->stored('image/png', 'not an image at all');
        $this->stored('application/pdf', '%PDF-1.4');
        $this->assertThrows(fn () => app(ImageCache::class)->canonical($refused));

        $this->artisan('openpne:image-cache', ['action' => 'status'])
            ->expectsOutputToContain('Pictures: 3')
            ->expectsOutputToContain('canonical: 1')
            ->expectsOutputToContain('cold:      1')
            ->expectsOutputToContain('refused:   1')
            ->expectsOutputToContain("#{$refused->id} {$refused->name}: ")
            ->assertSuccessful();

        $this->assertTrue(app(ImageCache::class)->hasCanonical($warm));
        $this->assertFalse(app(ImageCache::class)->hasCanonical($cold));
    }

    public function test_warm_generates_the_canonical_and_records_the_size_of_cold_pictures(): void
    {
        $cold = $this->stored('image/png', ImageBytes::png(64, 32));
        $sizeless = app(FileUploader::class)->store(UploadedFile::fake()->image('a.png', 8, 8));
        $sizeless->update(['width' => null, 'height' => null]);

        $this->artisan('openpne:image-cache', ['action' => 'warm'])
            ->expectsOutputToContain('Warmed 1 picture(s), recorded 2 size(s); 0 refused, 0 skipped as refused before')
            ->assertSuccessful();

        $this->assertTrue(app(ImageCache::class)->hasCanonical($cold));
        $this->assertSame([64, 32], [$cold->refresh()->width, $cold->height]);
        $this->assertSame([8, 8], [$sizeless->refresh()->width, $sizeless->height]);
    }

    public function test_warm_remembers_a_refusal_and_skips_it_until_asked_to_retry(): void
    {
        // Refused under a tight side limit, then accepted once the limit is raised and retried.
        $file = $this->stored('image/png', ImageBytes::png(64, 32));
        config(['openpne.images.max_upload_dimension' => 16]);

        $this->artisan('openpne:image-cache', ['action' => 'warm'])
            ->expectsOutputToContain('Warmed 0 picture(s), recorded 0 size(s); 1 refused,')
            ->assertSuccessful();
        $this->assertNotNull(app(ImageCache::class)->refusal($file));

        config(['openpne.images.max_upload_dimension' => 5000]);

        $this->artisan('openpne:image-cache', ['action' => 'warm'])
            ->expectsOutputToContain('0 refused, 1 skipped as refused before (pass --retry-failed)')
            ->assertSuccessful();
        $this->assertFalse(app(ImageCache::class)->hasCanonical($file));

        $this->artisan('openpne:image-cache', ['action' => 'warm', '--retry-failed' => true])
            ->expectsOutputToContain('Warmed 1 picture(s), recorded 1 size(s); 0 refused, 0 skipped')
            ->assertSuccessful();
        $this->assertTrue(app(ImageCache::class)->hasCanonical($file));
        $this->assertNull(app(ImageCache::class)->refusal($file));
    }

    public function test_rebuild_discards_what_the_encoder_made_and_makes_the_canonical_again(): void
    {
        $file = app(FileUploader::class)->store(UploadedFile::fake()->image('a.png', 64, 32));
        $variant = ImageTransform::fromGeometry('w120_h120')->cacheKey($file->name, 'png');
        app(ImageCache::class)->bytes($file, ImageTransform::fromGeometry('w120_h120'), 'png');
        Storage::disk('image_cache')->assertExists($variant);
        Storage::disk('image_cache')->put(ImageTransform::raw()->cacheKey($file->name, 'png'), 'STALE');

        $this->artisan('openpne:image-cache', ['action' => 'rebuild'])
            ->expectsOutputToContain('Warmed 1 picture(s)')
            ->assertSuccessful();

        Storage::disk('image_cache')->assertMissing($variant);
        $this->assertNotSame('STALE', Storage::disk('image_cache')->get(ImageTransform::raw()->cacheKey($file->name, 'png')));
        $this->assertSame([64, 32], array_slice((array) getimagesizefromstring(app(ImageCache::class)->canonical($file)), 0, 2));
    }

    public function test_a_processor_outage_is_counted_and_fails_the_run_without_a_marker(): void
    {
        $file = $this->stored('image/png', ImageBytes::png());
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

        $this->artisan('openpne:image-cache', ['action' => 'warm'])
            ->expectsOutputToContain('1 unavailable (processor down)')
            ->assertFailed();

        $this->assertNull(app(ImageCache::class)->refusal($file));
    }

    public function test_a_row_whose_bytes_are_gone_is_unreadable_and_the_run_goes_on(): void
    {
        File::factory()->create(['type' => 'image/png']);
        $good = $this->stored('image/png', ImageBytes::png());

        $this->artisan('openpne:image-cache', ['action' => 'warm'])
            ->expectsOutputToContain('Warmed 1 picture(s), recorded 1 size(s); 0 refused, 0 skipped as refused before (pass --retry-failed), 0 unavailable (processor down), 1 unreadable.')
            ->assertSuccessful();

        $this->assertTrue(app(ImageCache::class)->hasCanonical($good));
    }

    public function test_an_unknown_action_is_refused(): void
    {
        $this->artisan('openpne:image-cache', ['action' => 'purge'])->assertFailed();
    }

    public function test_the_old_backfill_name_still_warms(): void
    {
        $cold = $this->stored('image/png', ImageBytes::png(16, 8));

        $this->artisan('openpne:backfill-image-dimensions')->assertSuccessful();

        $this->assertSame([16, 8], [$cold->refresh()->width, $cold->height]);
    }

    private function stored(string $type, string $bytes): File
    {
        $file = File::factory()->create(['type' => $type, 'byte_size' => strlen($bytes)]);

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);
        app(FileStorage::class)->writeStream($file, $stream);
        fclose($stream);

        return $file;
    }
}
