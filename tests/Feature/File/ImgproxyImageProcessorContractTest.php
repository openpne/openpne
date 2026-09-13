<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Files\ImageProcessingException;
use App\Files\ImageProcessor;
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

    public function test_an_animation_over_the_frame_budget_is_kept_as_a_still(): void
    {
        // 200 frames of 600x600 are 72 MP in total, over the sidecar's 50 MP; one frame is well under.
        $canonical = $this->canonical($this->animatedGif(frames: 200, side: 600), 'image/gif');

        $this->assertSame(1, $this->frameCount($canonical->bytes));
        $this->assertFalse($canonical->animated);
        $this->assertSame([600, 600], [$canonical->width, $canonical->height]);
    }

    public function test_an_answer_over_the_source_limit_is_cut_short_and_refused(): void
    {
        // A 4x4 source under a 1 KB limit blown up to 2000x2000: the answer runs past the limit, so the
        // sink cuts the transfer — libcurl's write error here, not the mock's short write.
        config(['openpne.images.max_source_kilobytes' => 1]);

        try {
            $this->processor()->process($this->png(4, 4), 'image/png', ImageSpec::cover(2000, 2000, 'png'));
            $this->fail('An answer over the source limit was kept.');
        } catch (ImageProcessingException $e) {
            $this->assertStringContainsString('source limit', $e->getMessage());
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
