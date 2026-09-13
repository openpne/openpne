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
use Intervention\Gif\Builder;
use Tests\Support\ImageBytes;
use Tests\TestCase;

class ImageCacheCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_counts_warm_cold_refused_and_unshown_pictures_and_names_the_last_two(): void
    {
        $warm = app(FileUploader::class)->store(UploadedFile::fake()->image('a.png', 8, 8));
        $cold = $this->stored('image/png', ImageBytes::png());
        $refused = $this->stored('image/png', 'not an image at all');
        // OpenPNE 3 accepted this type; nothing here shows it as a picture, so it must at least be listed.
        $unshown = $this->stored('image/pjpeg', ImageBytes::png());
        $this->stored('application/pdf', '%PDF-1.4');
        $this->assertThrows(fn () => app(ImageCache::class)->canonical($refused));

        $this->artisan('openpne:image-cache', ['action' => 'status'])
            ->expectsOutputToContain('Pictures: 4')
            ->expectsOutputToContain('canonical: 1')
            ->expectsOutputToContain('cold:      1')
            ->expectsOutputToContain('refused:   1')
            ->expectsOutputToContain('unshown:   1')
            ->expectsOutputToContain('animated:  0')
            ->expectsOutputToContain('unknown:   2')
            ->expectsOutputToContain("#{$refused->id} {$refused->name}: ")
            ->expectsOutputToContain("#{$unshown->id} {$unshown->name}: image/pjpeg")
            ->assertSuccessful();

        $this->assertTrue(app(ImageCache::class)->hasCanonical($warm));
        $this->assertFalse(app(ImageCache::class)->hasCanonical($cold));
    }

    public function test_warm_generates_the_canonical_and_records_the_size_of_cold_pictures(): void
    {
        $cold = $this->stored('image/png', ImageBytes::png(64, 32));
        $sizeless = app(FileUploader::class)->store(UploadedFile::fake()->image('a.png', 8, 8));
        $sizeless->update(['width' => null, 'height' => null]);
        $unshown = $this->stored('image/x-png', ImageBytes::png());

        $this->artisan('openpne:image-cache', ['action' => 'warm'])
            ->expectsOutputToContain('Warmed 1 picture(s), recorded facts for 2.')
            ->expectsOutputToContain('unshown:     1')
            ->expectsOutputToContain("#{$unshown->id} {$unshown->name}: image/x-png")
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
            ->expectsOutputToContain('Warmed 0 picture(s), recorded facts for 0.')
            ->expectsOutputToContain('refused:     1')
            ->assertSuccessful();
        $this->assertNotNull(app(ImageCache::class)->refusal($file));

        config(['openpne.images.max_upload_dimension' => 5000]);

        $this->artisan('openpne:image-cache', ['action' => 'warm'])
            ->expectsOutputToContain('skipped:     1')
            ->assertSuccessful();
        $this->assertFalse(app(ImageCache::class)->hasCanonical($file));
        // The favicon verdict drawn from the refusal (App\Files\AppIcon) goes with it.
        $icon = ImageTransform::encoderPrefix($file->name).'/app-icon-32.refused';
        Storage::disk('image_cache')->put($icon, '');

        $this->artisan('openpne:image-cache', ['action' => 'warm', '--retry-failed' => true])
            ->expectsOutputToContain('Warmed 1 picture(s), recorded facts for 1.')
            ->assertSuccessful();
        $this->assertTrue(app(ImageCache::class)->hasCanonical($file));
        $this->assertNull(app(ImageCache::class)->refusal($file));
        Storage::disk('image_cache')->assertMissing($icon);
    }

    public function test_warm_rewrites_the_size_of_a_picture_it_makes_a_canonical_for(): void
    {
        // A size recorded under another encoder (ext-exif arriving, say) is a different picture's.
        $file = $this->stored('image/png', ImageBytes::png(64, 32));
        $file->update(['width' => 10, 'height' => 5]);

        $this->artisan('openpne:image-cache', ['action' => 'warm'])
            ->expectsOutputToContain('Warmed 1 picture(s), recorded facts for 1.')
            ->assertSuccessful();

        $this->assertSame([64, 32], [$file->refresh()->width, $file->height]);
    }

    public function test_a_retry_during_an_outage_keeps_the_remembered_reason(): void
    {
        $file = $this->stored('image/png', 'not an image at all');
        $this->assertThrows(fn () => app(ImageCache::class)->canonical($file));
        $reason = app(ImageCache::class)->refusal($file);
        $this->processorIsDown();

        $this->artisan('openpne:image-cache', ['action' => 'warm', '--retry-failed' => true])
            ->expectsOutputToContain('unavailable: 1')
            ->assertFailed();

        $this->assertSame($reason, app(ImageCache::class)->refusal($file));
    }

    public function test_warm_fills_a_missing_animated_fact_from_the_canonical_and_leaves_what_it_cannot_judge(): void
    {
        // Both have a canonical on the disk; one the probe reads, one cut short so it cannot.
        $known = app(FileUploader::class)->store(UploadedFile::fake()->image('a.png', 8, 8));
        $known->update(['animated' => null]);
        $unjudged = app(FileUploader::class)->store(UploadedFile::fake()->createWithContent('b.gif', $this->animatedGif()));
        $unjudged->update(['animated' => null]);
        Storage::disk('image_cache')->put(ImageTransform::raw()->cacheKey($unjudged->name, 'gif'), substr($this->animatedGif(), 0, 40));

        $this->artisan('openpne:image-cache', ['action' => 'warm'])
            ->expectsOutputToContain('Warmed 0 picture(s), recorded facts for 1.')
            ->assertSuccessful();

        $this->assertFalse($known->refresh()->animated);
        $this->assertNull($unjudged->refresh()->animated);
    }

    public function test_a_gif_over_the_walk_bound_stays_unknown_and_is_not_read(): void
    {
        // Configured in kilobytes; the canonical on the disk is over it, so the bytes are not fetched.
        config(['openpne.images.max_gif_walk_kilobytes' => 1]);
        $file = app(FileUploader::class)->store(UploadedFile::fake()->createWithContent('a.gif', $this->animatedGif()));
        $file->update(['animated' => null]);
        Storage::disk('image_cache')->put(ImageTransform::raw()->cacheKey($file->name, 'gif'), str_pad($this->animatedGif(), 2048, "\0"));

        $this->artisan('openpne:image-cache', ['action' => 'warm'])
            ->expectsOutputToContain('Warmed 0 picture(s), recorded facts for 0.')
            ->assertSuccessful();
        $this->assertNull($file->refresh()->animated);

        config(['openpne.images.max_gif_walk_kilobytes' => 0]);

        $this->artisan('openpne:image-cache', ['action' => 'warm'])
            ->expectsOutputToContain('Warmed 0 picture(s), recorded facts for 1.')
            ->assertSuccessful();
        $this->assertTrue($file->refresh()->animated);
    }

    public function test_a_processor_that_cannot_tell_leaves_the_recorded_fact_alone(): void
    {
        $file = app(FileUploader::class)->store(UploadedFile::fake()->createWithContent('a.gif', $this->animatedGif()));
        $file->update(['animated' => true]);
        $inner = $this->app->make(ImageProcessor::class);
        $this->app->instance(ImageProcessor::class, new class($inner) implements ImageProcessor
        {
            public function __construct(private readonly ImageProcessor $inner) {}

            public function process(string $bytes, string $mime, ImageSpec $spec): ProcessedImage
            {
                $processed = $this->inner->process($bytes, $mime, $spec);

                return new ProcessedImage($processed->bytes, $processed->mime, $processed->width, $processed->height, null);
            }

            public function preservesAnimation(): bool
            {
                return true;
            }
        });
        $this->app->forgetInstance(ImageCache::class);

        $this->artisan('openpne:image-cache', ['action' => 'rebuild'])
            ->expectsOutputToContain('Rebuilt 1 picture(s), recorded facts for 0.')
            ->assertSuccessful();

        $this->assertTrue($file->refresh()->animated);
    }

    public function test_rebuild_rewrites_the_animated_fact_when_the_processor_disagrees_with_the_row(): void
    {
        // Same size, another verdict: the guard that skips an unchanged size must not skip this.
        $file = app(FileUploader::class)->store(UploadedFile::fake()->image('a.png', 8, 8));
        $file->update(['animated' => true]);

        $this->artisan('openpne:image-cache', ['action' => 'rebuild'])
            ->expectsOutputToContain('Rebuilt 1 picture(s), recorded facts for 1.')
            ->assertSuccessful();

        $this->assertFalse($file->refresh()->animated);
    }

    public function test_rebuild_discards_every_derived_file_and_makes_the_canonical_again(): void
    {
        $file = app(FileUploader::class)->store(UploadedFile::fake()->image('a.png', 64, 32));
        $variant = ImageTransform::fromGeometry('w120_h120')->cacheKey($file->name, 'png');
        app(ImageCache::class)->bytes($file, ImageTransform::fromGeometry('w120_h120'), 'png');
        Storage::disk('image_cache')->assertExists($variant);
        Storage::disk('image_cache')->put(ImageTransform::raw()->cacheKey($file->name, 'png'), 'STALE');
        // A quality change moves the encoder directory; the old one is only ever reclaimed here.
        Storage::disk('image_cache')->put("{$file->name}/g3/gd-q60/w_h.png", 'OLD ENCODER');
        // A size recorded under another encoder (ext-exif arriving, say) is a different picture's.
        $file->update(['width' => 10, 'height' => 5]);

        $this->artisan('openpne:image-cache', ['action' => 'rebuild'])
            ->expectsOutputToContain('Rebuilt 1 picture(s), recorded facts for 1.')
            ->assertSuccessful();

        Storage::disk('image_cache')->assertMissing($variant);
        Storage::disk('image_cache')->assertMissing("{$file->name}/g3/gd-q60/w_h.png");
        $this->assertNotSame('STALE', Storage::disk('image_cache')->get(ImageTransform::raw()->cacheKey($file->name, 'png')));
        $this->assertSame([64, 32], array_slice((array) getimagesizefromstring(app(ImageCache::class)->canonical($file)), 0, 2));
        $this->assertSame([64, 32], [$file->refresh()->width, $file->height]);
    }

    public function test_rebuild_keeps_what_it_has_when_the_bytes_cannot_be_read_or_the_processor_is_down(): void
    {
        $gone = app(FileUploader::class)->store(UploadedFile::fake()->image('a.png', 8, 8));
        $variant = ImageTransform::fromGeometry('w120_h120')->cacheKey($gone->name, 'png');
        app(ImageCache::class)->bytes($gone, ImageTransform::fromGeometry('w120_h120'), 'png');
        app(FileStorage::class)->delete($gone);

        $this->artisan('openpne:image-cache', ['action' => 'rebuild'])
            ->expectsOutputToContain('unreadable:  1')
            ->expectsOutputToContain("#{$gone->id} {$gone->name}: ")
            ->assertFailed();

        $this->assertTrue(app(ImageCache::class)->hasCanonical($gone));
        Storage::disk('image_cache')->assertExists($variant);

        $sound = app(FileUploader::class)->store(UploadedFile::fake()->image('b.png', 8, 8));
        $this->processorIsDown();

        $this->artisan('openpne:image-cache', ['action' => 'rebuild'])
            ->expectsOutputToContain('unavailable: 1')
            ->assertFailed();

        $this->assertTrue(app(ImageCache::class)->hasCanonical($sound));
    }

    public function test_a_processor_outage_is_counted_and_fails_the_run_without_a_marker(): void
    {
        $file = $this->stored('image/png', ImageBytes::png());
        $this->processorIsDown();

        $this->artisan('openpne:image-cache', ['action' => 'warm'])
            ->expectsOutputToContain('unavailable: 1')
            ->assertFailed();

        $this->assertNull(app(ImageCache::class)->refusal($file));
    }

    public function test_a_row_whose_bytes_are_gone_fails_the_run_and_the_run_goes_on(): void
    {
        $missing = File::factory()->create(['type' => 'image/png']);
        $good = $this->stored('image/png', ImageBytes::png());

        $this->artisan('openpne:image-cache', ['action' => 'warm'])
            ->expectsOutputToContain('Warmed 1 picture(s), recorded facts for 1.')
            ->expectsOutputToContain('unreadable:  1')
            ->expectsOutputToContain("#{$missing->id} {$missing->name}: ")
            ->assertFailed();

        $this->assertTrue(app(ImageCache::class)->hasCanonical($good));
    }

    public function test_a_cache_disk_that_refuses_the_write_fails_the_run(): void
    {
        $this->skipUnlessModeBitsBind();

        // Four cold pictures but three refusals counted: the run stops rather than discard any more.
        $file = $this->stored('image/png', ImageBytes::png());
        foreach (range(1, 3) as $more) {
            $this->stored('image/png', ImageBytes::png());
        }
        $root = Storage::disk('image_cache')->path('');
        chmod($root, 0o500);

        try {
            $this->artisan('openpne:image-cache', ['action' => 'warm'])
                ->expectsOutputToContain('Warmed 0 picture(s)')
                ->expectsOutputToContain('unwritten:   3  (the cache disk refused the write; the run stopped after 3 in a row)')
                ->assertFailed();
        } finally {
            chmod($root, 0o755);
        }

        $this->assertFalse(app(ImageCache::class)->hasCanonical($file));
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

    /** Three frames of one shade each on an 8x8 screen. */
    private function animatedGif(): string
    {
        $builder = Builder::canvas(8, 8);

        foreach ([40, 140, 240] as $shade) {
            $gd = imagecreate(8, 8);
            imagecolorallocate($gd, $shade, 40, 200);
            ob_start();
            imagegif($gd);
            $builder->addFrame(source: (string) ob_get_clean(), delay: 0.1);
        }

        return $builder->encode();
    }

    private function processorIsDown(): void
    {
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
        $this->app->forgetInstance(ImageCache::class);
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
