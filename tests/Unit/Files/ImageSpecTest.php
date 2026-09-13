<?php

declare(strict_types=1);

namespace Tests\Unit\Files;

use App\Files\ImageSpec;
use InvalidArgumentException;
use Tests\TestCase;

class ImageSpecTest extends TestCase
{
    public function test_a_fit_may_ask_for_its_frames_and_keeps_the_ask_through_a_background(): void
    {
        $spec = ImageSpec::fit(120, 120, 'gif')->animated()->withBackground('ffffff');

        $this->assertTrue($spec->animated);
        $this->assertFalse(ImageSpec::fit(120, 120, 'gif')->animated);
        $this->assertFalse(ImageSpec::canonical('gif')->animated);
    }

    public function test_a_cover_cannot_ask_for_its_frames(): void
    {
        // A cover upscales every frame, so its frames times pixels escape the budget a canonical met.
        $this->expectException(InvalidArgumentException::class);

        ImageSpec::cover(120, 120, 'gif')->animated();
    }
}
