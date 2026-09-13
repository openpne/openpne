<?php

namespace Tests\Unit\Files;

use App\Files\GdImageProcessor;
use App\Files\ImageLadder;
use App\Files\ImageProcessor;
use App\Files\ImageTransform;
use App\Models\File;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use Tests\Support\FrameKeepingProcessor;
use Tests\TestCase;

class ImageLadderTest extends TestCase
{
    private const KEYS = ['thumbnailUrl', 'fitSources', 'cropSources', 'width', 'height', 'animatedSources'];

    public function test_an_animating_file_offers_an_animated_rung_per_animated_size(): void
    {
        $this->keepFrames();
        $file = File::factory()->make(['type' => 'image/gif', 'animated' => true]);

        $this->assertSame([
            ['url' => $file->thumbnailUrl(320, 320, animated: true), 'box' => 320],
            ['url' => $file->thumbnailUrl(640, 640, animated: true), 'box' => 640],
            ['url' => $file->thumbnailUrl(1200, 1200, animated: true), 'box' => 1200],
        ], ImageLadder::of($file)['animatedSources']);
    }

    public function test_a_still_or_unrecorded_file_offers_none(): void
    {
        // null is "not yet known": an `_a` URL for it would 404, so the still ladder is the answer.
        $this->keepFrames();

        $this->assertSame([], ImageLadder::of(File::factory()->make(['type' => 'image/gif', 'animated' => false]))['animatedSources']);
        $this->assertSame([], ImageLadder::of(File::factory()->make(['type' => 'image/gif', 'animated' => null]))['animatedSources']);
    }

    public function test_a_processor_that_drops_frames_offers_none_whatever_the_file_recorded(): void
    {
        // GD bound by name rather than taken from the lane, which under imgproxy would keep frames: a
        // true recorded before a switch back must not produce an `_a` URL that answers with a still.
        $this->app->instance(ImageProcessor::class, new GdImageProcessor(new ImageManager(GdDriver::class, decodeAnimation: false)));
        $file = File::factory()->make(['type' => 'image/gif', 'animated' => true]);

        $this->assertSame([], ImageLadder::of($file)['animatedSources']);
    }

    public function test_no_file_is_an_empty_ladder_with_every_key(): void
    {
        $ladder = ImageLadder::of(null);

        $this->assertSame(self::KEYS, array_keys($ladder));
        $this->assertSame([], $ladder['fitSources']);
        $this->assertSame([], $ladder['cropSources']);
        $this->assertSame([], $ladder['animatedSources']);
        $this->assertSame('', $ladder['thumbnailUrl']);
    }

    public function test_every_animated_size_is_a_geometry_the_route_answers(): void
    {
        // `animated_sizes` must sit inside `allowed_sizes`, which ImageTransform checks first; a size
        // in one list only would be offered here and 404 there.
        foreach (config('openpne.images.animated_sizes') as $size) {
            [$width, $height] = explode('x', $size);

            $this->assertNotNull(ImageTransform::fromGeometry("w{$width}_h{$height}_a"), $size);
        }
    }

    private function keepFrames(): void
    {
        $this->app->instance(ImageProcessor::class, new FrameKeepingProcessor);
    }
}
