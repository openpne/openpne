<?php

namespace Tests\Unit\Files;

use App\Files\ImageTransform;
use Tests\TestCase;

class ImageTransformTest extends TestCase
{
    public function test_parses_a_whitelisted_size(): void
    {
        $t = ImageTransform::fromGeometry('w120_h120');

        $this->assertNotNull($t);
        $this->assertSame(120, $t->width);
        $this->assertSame(120, $t->height);
        $this->assertFalse($t->square);
        $this->assertFalse($t->isRaw());
    }

    public function test_parses_a_square_size(): void
    {
        $t = ImageTransform::fromGeometry('w120_h120_sq');

        $this->assertNotNull($t);
        $this->assertTrue($t->square);
    }

    public function test_parses_a_non_square_whitelisted_size(): void
    {
        $this->assertNotNull(ImageTransform::fromGeometry('w240_h320'));
    }

    public function test_parses_the_original_size(): void
    {
        $t = ImageTransform::fromGeometry('w_h');

        $this->assertNotNull($t);
        $this->assertTrue($t->isRaw());
    }

    public function test_rejects_a_non_whitelisted_size(): void
    {
        $this->assertNull(ImageTransform::fromGeometry('w999_h999'));
    }

    public function test_rejects_a_square_original(): void
    {
        // A square crop needs concrete dimensions.
        $this->assertNull(ImageTransform::fromGeometry('w_h_sq'));
    }

    public function test_rejects_a_partial_size(): void
    {
        $this->assertNull(ImageTransform::fromGeometry('w120_h'));
    }

    public function test_rejects_malformed_geometry(): void
    {
        $this->assertNull(ImageTransform::fromGeometry('garbage'));
        $this->assertNull(ImageTransform::fromGeometry('120x120'));
    }

    public function test_cache_key_layout(): void
    {
        $this->assertSame('abc/w120_h120_sq.png', ImageTransform::fromGeometry('w120_h120_sq')->cacheKey('abc', 'png'));
    }
}
