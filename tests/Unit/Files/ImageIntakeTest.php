<?php

declare(strict_types=1);

namespace Tests\Unit\Files;

use App\Files\ImageIntake;
use Tests\TestCase;

class ImageIntakeTest extends TestCase
{
    public function test_gd_reads_the_four_types_it_always_has_and_bounds_each_side(): void
    {
        config(['openpne.images.max_upload_dimension' => 3000, 'openpne.images.max_source_pixels' => 0]);
        $intake = ImageIntake::gd();

        $this->assertSame(['image/jpeg', 'image/png', 'image/gif', 'image/webp'], $intake->mimes());
        $this->assertSame('jpg', $intake->canonicalFormat('image/jpeg'));
        $this->assertNull($intake->canonicalFormat('image/heic'));
        $this->assertNull($intake->canonicalFormat('image/avif'));
        $this->assertTrue($intake->decodesInProcess());
        $this->assertSame(3000, $intake->sideLimit());
        $this->assertSame(9_000_000, $intake->pixelLimit());
        $this->assertSame('image/jpeg,image/png,image/gif,image/webp', $intake->accept());
    }

    public function test_the_sidecar_reads_heic_and_avif_into_formats_a_browser_shows(): void
    {
        config(['openpne.images.max_source_pixels' => 0]);
        $intake = ImageIntake::imgproxy();

        $this->assertSame('jpg', $intake->canonicalFormat('image/heic'));
        $this->assertSame('jpg', $intake->canonicalFormat('image/heif'));
        $this->assertSame('webp', $intake->canonicalFormat('image/avif'));
        $this->assertNull($intake->canonicalFormat('image/heic-sequence'));
        $this->assertFalse($intake->decodesInProcess());
        $this->assertNull($intake->sideLimit());
        $this->assertSame(ImageIntake::SIDECAR_MEGAPIXELS * 1_000_000, $intake->pixelLimit());
        $this->assertSame('image/jpeg,image/png,image/gif,image/webp,image/heic,image/heif,image/avif,.heic,.heif,.avif', $intake->accept());
    }

    public function test_a_configured_pixel_cap_is_taken_as_given_by_both(): void
    {
        config(['openpne.images.max_source_pixels' => 1_000_000]);

        $this->assertSame(1_000_000, ImageIntake::gd()->pixelLimit());
        $this->assertSame(1_000_000, ImageIntake::imgproxy()->pixelLimit());
    }
}
