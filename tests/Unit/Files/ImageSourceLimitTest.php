<?php

declare(strict_types=1);

namespace Tests\Unit\Files;

use App\Files\ImageIntake;
use App\Files\ImageProcessingException;
use App\Files\ImageSourceLimit;
use App\Files\ImageTooLargeException;
use Tests\TestCase;

class ImageSourceLimitTest extends TestCase
{
    public function test_a_blank_setting_is_the_default_byte_cap_and_the_gd_default_pixels(): void
    {
        config([
            'openpne.images.max_source_kilobytes' => '',
            'openpne.images.max_source_pixels' => 0,
            'openpne.images.max_upload_kilobytes' => 5120,
            'openpne.images.max_upload_dimension' => 5000,
        ]);

        $this->assertSame(20480 * 1024, ImageSourceLimit::bytes());
        $this->assertSame(12_600_000, ImageSourceLimit::pixels());
    }

    public function test_the_byte_default_moves_with_the_upload_cap_and_the_pixels_do_not(): void
    {
        // Raising what an upload may be must not leave the stored copy refused for its length; the
        // pixels stay what the default memory_limit decodes.
        config([
            'openpne.images.max_source_kilobytes' => 0,
            'openpne.images.max_source_pixels' => 0,
            'openpne.images.max_upload_kilobytes' => 30720,
            'openpne.images.max_upload_dimension' => 6000,
        ]);

        $this->assertSame(30720 * 1024, ImageSourceLimit::bytes());
        $this->assertSame(12_600_000, ImageSourceLimit::pixels());
    }

    public function test_a_lower_side_limit_lowers_the_pixel_default_to_a_square_at_that_side(): void
    {
        config(['openpne.images.max_source_pixels' => 0, 'openpne.images.max_upload_dimension' => 2000]);

        $this->assertSame(2048 * 2000, ImageSourceLimit::pixels());
    }

    public function test_a_blank_side_limit_does_not_collapse_the_pixel_cap_to_nothing(): void
    {
        config(['openpne.images.max_source_pixels' => 0, 'openpne.images.max_upload_dimension' => '']);

        $this->assertSame(12_600_000, ImageSourceLimit::pixels());
    }

    public function test_gd_counts_each_row_rounded_up_to_1024_pixels_and_a_quarter_turn_both_ways(): void
    {
        $this->assertSame(1024 * 10, ImageIntake::gdPixelCost(1024, 10));
        $this->assertSame(2048 * 10, ImageIntake::gdPixelCost(1025, 10));
        $this->assertSame(4096 * 3076, ImageIntake::gdPixelCost(3073, 3076));
        $this->assertSame(4096 * 3024, ImageIntake::gdPixelCost(4032, 3024, quarterTurn: true));
        // Turned upright, a 3073 px tall picture is 3073 px wide.
        $this->assertSame(4096 * 2000, ImageIntake::gdPixelCost(2000, 3073, quarterTurn: true));
        $this->assertSame(2048 * 3073, ImageIntake::gdPixelCost(2000, 3073));
    }

    public function test_the_sidecar_counts_plain_pixels(): void
    {
        $this->assertSame(3073 * 3076, ImageIntake::imgproxy()->pixelCost(3073, 3076, true));
        $this->assertSame(4096 * 3076, ImageIntake::gd()->pixelCost(3073, 3076, false));
    }

    public function test_the_phone_photos_pass_the_gd_default_and_a_width_just_past_a_multiple_is_held_to_it(): void
    {
        config(['openpne.images.max_source_pixels' => 0, 'openpne.images.max_upload_dimension' => 5000]);

        foreach ([[4032, 3024], [4080, 3072], [3024, 4032], [3072, 4080], [3073, 3076]] as [$w, $h]) {
            ImageSourceLimit::preflight($this->jpeg($w, $h, 6), ImageIntake::gd());
        }

        // 4096 x 3077 rounded, or with the turn 3073 px wide rows of 4096.
        foreach ([[$this->jpeg(3073, 3077, 1), 'plain'], [$this->jpeg(3080, 4097, 6), 'turned']] as [$bytes, $name]) {
            try {
                ImageSourceLimit::preflight($bytes, ImageIntake::gd());
                $this->fail("{$name}: over the rounded limit was let through.");
            } catch (ImageTooLargeException $e) {
                $this->assertStringContainsString('pixel limit', $e->getMessage(), $name);
            }
        }
    }

    public function test_the_turn_is_read_from_the_exif_so_only_a_quarter_turn_counts_both_ways(): void
    {
        if (! extension_loaded('exif')) {
            $this->markTestSkipped('The turn is read only with ext-exif.');
        }
        // 3072 x 4097: 3072 x 4097 upright, but turned the 4097 px rows round up to 5120.
        config(['openpne.images.max_source_pixels' => 3072 * 4097, 'openpne.images.max_upload_dimension' => 5000]);

        foreach ([1, 2, 3, 4] as $orientation) {
            ImageSourceLimit::preflight($this->jpeg(3072, 4097, $orientation), ImageIntake::gd());
        }

        foreach ([5, 6, 7, 8] as $orientation) {
            try {
                ImageSourceLimit::preflight($this->jpeg(3072, 4097, $orientation), ImageIntake::gd());
                $this->fail("Orientation {$orientation} was counted unturned.");
            } catch (ImageTooLargeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_memory_formula_holds_the_shipped_defaults_inside_128m_and_256m(): void
    {
        config(['openpne.images.max_upload_kilobytes' => 5120]);

        $this->assertGreaterThanOrEqual(ImageSourceLimit::DEFAULT_GD_PIXELS, ImageSourceLimit::pixelsFor(128 * 1024 * 1024));
        $this->assertGreaterThanOrEqual(ImageIntake::gdPixelCost(5000, 5000), ImageSourceLimit::pixelsFor(256 * 1024 * 1024));
        $this->assertLessThan(ImageSourceLimit::DEFAULT_GD_PIXELS, ImageSourceLimit::pixelsFor(96 * 1024 * 1024));
        $this->assertSame(128, ImageSourceLimit::megabytesFor(ImageSourceLimit::pixelsFor(128 * 1024 * 1024)));
        $this->assertLessThanOrEqual(0, ImageSourceLimit::pixelsFor(30 * 1024 * 1024));
    }

    public function test_an_unreadable_header_passes_only_for_a_container_only_the_sidecar_reads(): void
    {
        // A HEIC whose header this PHP cannot read (none before 8.5) is the sidecar's to measure; a
        // container PHP does read, or none at all, is refused under both, so a 500 never loops.
        $heicWithoutADeclaredSize = "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic".str_repeat("\x00", 64);
        $headerOnlyWebp = 'RIFF'.pack('V', 4).'WEBP';

        ImageSourceLimit::preflight($heicWithoutADeclaredSize, ImageIntake::imgproxy());

        foreach ([[$heicWithoutADeclaredSize, ImageIntake::gd()], [$headerOnlyWebp, ImageIntake::imgproxy()], ['not a picture', ImageIntake::imgproxy()]] as [$bytes, $intake]) {
            try {
                ImageSourceLimit::preflight($bytes, $intake);
                $this->fail('An unmeasured source was let through.');
            } catch (ImageProcessingException $e) {
                $this->assertStringContainsString('does not declare a size', $e->getMessage());
            }
        }
    }

    public function test_a_side_over_the_limit_is_refused_only_where_the_intake_has_one(): void
    {
        // Lane-independent teeth for the sidecar's missing per-side limit: the imgproxy contract test
        // pins it too, but only where a sidecar answers.
        config(['openpne.images.max_upload_dimension' => 100, 'openpne.images.max_source_pixels' => 1_000_000_000]);
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $wide = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NN', 200, 10)."\x08\x06\x00\x00\x00").$chunk('IEND', '');

        ImageSourceLimit::preflight($wide, ImageIntake::imgproxy());

        $this->expectException(ImageProcessingException::class);
        ImageSourceLimit::preflight($wide, ImageIntake::gd());
    }

    public function test_a_readable_header_over_the_cap_is_refused_under_both(): void
    {
        config(['openpne.images.max_source_pixels' => 100]);
        $png = imagecreatetruecolor(20, 20);
        ob_start();
        imagepng($png);
        $bytes = (string) ob_get_clean();

        foreach ([ImageIntake::gd(), ImageIntake::imgproxy()] as $intake) {
            try {
                ImageSourceLimit::preflight($bytes, $intake);
                $this->fail('400 pixels passed a 100 pixel cap.');
            } catch (ImageProcessingException $e) {
                $this->assertStringContainsString('20x20', $e->getMessage());
            }
        }
    }

    public function test_a_set_value_is_taken_as_given(): void
    {
        config([
            'openpne.images.max_source_kilobytes' => 1024,
            'openpne.images.max_source_pixels' => 4_000_000,
            'openpne.images.max_upload_dimension' => 5000,
        ]);

        $this->assertSame(1024 * 1024, ImageSourceLimit::bytes());
        $this->assertSame(4_000_000, ImageSourceLimit::pixels());
    }

    /** A real 8 x 8 JPEG with an EXIF Orientation spliced in and its frame header rewritten to declare $width x $height. */
    private function jpeg(int $width, int $height, int $orientation): string
    {
        ob_start();
        imagejpeg(imagecreatetruecolor(8, 8));
        $jpeg = (string) ob_get_clean();
        $sof = strpos($jpeg, "\xFF\xC0");
        $jpeg = substr_replace($jpeg, pack('nn', $height, $width), $sof + 5, 4);
        $tiff = 'MM'.pack('nN', 42, 8).pack('n', 1).pack('nnNnn', 0x0112, 3, 1, $orientation, 0).pack('N', 0);
        $app1 = "Exif\0\0".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
    }
}
