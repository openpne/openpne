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

    public function test_parses_every_rung_of_the_fit_ladder(): void
    {
        foreach ([320, 640, 1200] as $box) {
            $t = ImageTransform::fromGeometry("w{$box}_h{$box}");

            $this->assertNotNull($t, "fit box {$box}");
            $this->assertSame($box, $t->width);
            $this->assertFalse($t->square);
        }
    }

    public function test_parses_a_crop_at_a_cell_ratio(): void
    {
        // Modern crops server-side at the cell's own ratio, so `_sq` has to carry a rectangle and
        // not just a square (docs/internals/images.md).
        $ladder = ['w300_h400_sq' => [300, 400], 'w600_h800_sq' => [600, 800], 'w300_h200_sq' => [300, 200], 'w600_h400_sq' => [600, 400]];

        foreach ($ladder as $geometry => [$width, $height]) {
            $t = ImageTransform::fromGeometry($geometry);

            $this->assertNotNull($t, $geometry);
            $this->assertSame($width, $t->width, $geometry);
            $this->assertSame($height, $t->height, $geometry);
            $this->assertTrue($t->square, $geometry);
        }
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

    public function test_etag_is_the_hashed_cache_key(): void
    {
        config(['openpne.images.driver' => 'gd', 'openpne.images.quality' => 85, 'openpne.images.exif' => true]);
        $transform = ImageTransform::fromGeometry('w120_h120_sq');

        // Derived from the key so that whatever makes a new disk variant moves the tag with it.
        $this->assertSame('"'.sha1('abc/g2/gd-q85/w120_h120_sq.png').'"', $transform->etag('abc', 'png'));
        $this->assertNotSame($transform->etag('abc', 'png'), ImageTransform::fromGeometry('w_h')->etag('abc', 'png'));
        $this->assertNotSame($transform->etag('abc', 'png'), $transform->etag('abc', 'jpg'));
    }

    public function test_the_encoder_is_part_of_the_key(): void
    {
        config(['openpne.images.driver' => 'gd', 'openpne.images.quality' => 85, 'openpne.images.exif' => true]);
        $transform = ImageTransform::fromGeometry('w120_h120_sq');
        $before = $transform->cacheKey('abc', 'png');

        config(['openpne.images.quality' => 95]);
        $this->assertNotSame($before, $transform->cacheKey('abc', 'png'));

        config(['openpne.images.quality' => 85, 'openpne.images.driver' => 'imagick']);
        $this->assertNotSame($before, $transform->cacheKey('abc', 'png'));

        config(['openpne.images.driver' => 'gd', 'openpne.images.exif' => false]);
        $this->assertSame('abc/g2/gd-q85-noexif/w120_h120_sq.png', $transform->cacheKey('abc', 'png'));
    }

    public function test_the_original_is_keyed_without_an_encoder(): void
    {
        // w_h is passed through unencoded, so quality must not move its key — or refetch its bytes.
        config(['openpne.images.driver' => 'gd', 'openpne.images.quality' => 85, 'openpne.images.exif' => true]);
        $raw = ImageTransform::fromGeometry('w_h');
        $before = $raw->cacheKey('abc', 'png');

        config(['openpne.images.quality' => 95, 'openpne.images.driver' => 'imagick', 'openpne.images.exif' => false]);
        $this->assertSame($before, $raw->cacheKey('abc', 'png'));
        $this->assertSame('abc/g2/w_h.png', $before);
    }

    public function test_cache_key_layout(): void
    {
        config(['openpne.images.driver' => 'gd', 'openpne.images.quality' => 85, 'openpne.images.exif' => true]);
        // The generation segment sits under the file's own directory so that purging the
        // file still takes every variant it ever had with it.
        $this->assertSame('abc/g2/gd-q85/w120_h120_sq.png', ImageTransform::fromGeometry('w120_h120_sq')->cacheKey('abc', 'png'));
    }
}
