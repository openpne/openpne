<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Files\AnimationProbe;
use App\Files\ImageProcessingException;
use App\Files\ImageProcessor;
use App\Files\ImageProcessorUnavailableException;
use App\Files\ImageSpec;
use App\Files\Imgproxy\ImgproxyImageProcessor;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Runs against a live sidecar: the CI lane names one, elsewhere every case is skipped. */
class ImgproxyImageProcessorContractTest extends ImageProcessorContractTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (env('OPENPNE_IMAGE_PROCESSOR') !== 'imgproxy') {
            $this->markTestSkipped('OPENPNE_IMAGE_PROCESSOR=imgproxy, with a sidecar to talk to, runs this.');
        }
    }

    protected function processor(): ImageProcessor
    {
        config(['openpne.images.processor' => 'imgproxy']);
        $this->app->forgetInstance(ImageProcessor::class);

        return $this->app->make(ImageProcessor::class);
    }

    public function test_the_processor_is_the_sidecar(): void
    {
        $this->assertInstanceOf(ImgproxyImageProcessor::class, $this->processor());
    }

    public function test_an_embedded_colour_profile_does_not_survive(): void
    {
        $canonical = $this->canonical($this->fixture('jpeg-app2-mixed.jpg'), 'image/jpeg');

        $this->assertStringNotContainsString('ICC_PROFILE', $canonical->bytes);
        $this->assertStringNotContainsString('ICCKEEPME', $canonical->bytes);
    }

    public function test_a_hollow_header_over_the_budget_is_refused_by_the_app_not_the_sidecar(): void
    {
        // The sidecar answers a hollow PNG with a 500 (an outage), so the app's own read of the header
        // is what keeps this a refusal; there is no side limit here, only the pixel budget.
        config(['openpne.images.max_upload_dimension' => 100]);

        try {
            $this->processor()->process($this->pngHeaderClaiming(200, 10), 'image/png', ImageSpec::canonical('png'));
            $this->fail('200x10 passed with no side limit only if the budget was not applied either.');
        } catch (ImageProcessingException $e) {
            $this->fail('A 200 px side is within the sidecar budget and must not be refused: '.$e->getMessage());
        } catch (ImageProcessorUnavailableException) {
            // A hollow PNG within the budget reaches the sidecar, which cannot load it: an outage, as documented.
        }

        try {
            $this->processor()->process($this->pngHeaderClaiming(40000, 40000), 'image/png', ImageSpec::canonical('png'));
            $this->fail('1.6 GP passed the sidecar budget.');
        } catch (ImageProcessingException $e) {
            $this->assertStringContainsString('pixel limit', $e->getMessage());
        }
    }

    public function test_an_animated_fit_asked_for_as_webp_keeps_its_frames(): void
    {
        $variant = $this->processor()->process($this->animatedGif(), 'image/gif', ImageSpec::fit(120, 120, 'webp')->animated());

        $this->assertSame('image/webp', $variant->mime);
        $this->assertTrue(AnimationProbe::of($variant->bytes, 'image/webp'));
    }

    public function test_a_heic_is_answered_as_a_clean_upright_jpeg(): void
    {
        // 12x6 declaring Orientation 6 with a GPS sentinel, as the fixture README describes.
        $canonical = $this->canonical($this->fixture('heic-gps-orientation.heic'), 'image/heic');

        $this->assertSame('image/jpeg', $canonical->mime);
        $this->assertSame([6, 12], [$canonical->width, $canonical->height]);
        $this->assertStringNotContainsString('2021:07:04', $canonical->bytes);
        $this->assertFalse($canonical->animated);
    }

    public function test_an_avif_is_answered_as_a_clean_webp(): void
    {
        $canonical = $this->canonical($this->fixture('avif-copyright.avif'), 'image/avif');

        $this->assertSame('image/webp', $canonical->mime);
        $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring($canonical->bytes)[2]);
        $this->assertStringNotContainsString('COPYRIGHT-LEAK', $canonical->bytes);
    }

    public function test_the_mime_handed_over_is_advisory(): void
    {
        // A stored HEIC row is typed image/jpeg (its canonical's type) and regenerated under that label.
        $canonical = $this->processor()->process($this->fixture('heic-gps-orientation.heic'), 'image/jpeg', ImageSpec::canonical('jpg'));

        $this->assertSame('image/jpeg', $canonical->mime);
        $this->assertSame([6, 12], [$canonical->width, $canonical->height]);
    }

    public function test_an_animation_over_the_frame_budget_is_kept_as_a_still(): void
    {
        // 200 frames of 600x600 are 72 MP in total, over the sidecar's 50 MP; one frame is well under.
        $canonical = $this->canonical($this->animatedGif(frames: 200, side: 600), 'image/gif');

        $this->assertSame(1, $this->frameCount($canonical->bytes));
        $this->assertFalse($canonical->animated);
        $this->assertSame([600, 600], [$canonical->width, $canonical->height]);
    }

    public function test_an_answer_over_the_cap_is_cut_short_by_the_sink(): void
    {
        // A 4x4 source under a 1 KB limit blown up to 2000x2000 runs past a variant's 2 KB cap, so the
        // sink cuts the transfer — libcurl's write error here, not the mock's short write.
        config(['openpne.images.max_source_kilobytes' => 1]);

        try {
            $this->processor()->process($this->png(4, 4), 'image/png', ImageSpec::cover(2000, 2000, 'png'));
            $this->fail('An answer over the cap was kept.');
        } catch (ImageProcessorUnavailableException $e) {
            $this->assertStringContainsString('2048 byte cap for a variant', $e->getMessage());
        }
    }

    public function test_the_spool_is_empty_after_a_request_whatever_the_answer(): void
    {
        // A directory of this test's own, since parallel processes spool into the shared one.
        $own = 'test-'.Str::random(8);
        config([
            'filesystems.disks.image_spool.root' => storage_path("app/image-spool/{$own}"),
            'openpne.images.imgproxy.source_prefix' => config('openpne.images.imgproxy.source_prefix').$own.'/',
        ]);
        Storage::forgetDisk('image_spool');

        try {
            $this->canonical($this->fixture('tiny.gif'), 'image/gif');
            $this->assertThrows(fn () => $this->canonical('definitely not a picture', 'image/png'));

            $this->assertSame([], Storage::disk('image_spool')->files());
        } finally {
            File::deleteDirectory(storage_path("app/image-spool/{$own}"));
        }
    }
}
