<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Files\ImageProcessor;
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
