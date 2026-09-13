<?php

declare(strict_types=1);

namespace Tests\Unit\Files;

use App\Files\ImageIntake;
use App\Files\ImageProcessingException;
use App\Files\ImageSourceLimit;
use Tests\TestCase;

class ImageSourceLimitTest extends TestCase
{
    public function test_a_blank_setting_follows_the_upload_rules_rather_than_lifting_the_cap(): void
    {
        config([
            'openpne.images.max_source_kilobytes' => '',
            'openpne.images.max_source_pixels' => 0,
            'openpne.images.max_upload_kilobytes' => 5120,
            'openpne.images.max_upload_dimension' => 5000,
        ]);

        $this->assertSame(20480 * 1024, ImageSourceLimit::bytes());
        $this->assertSame(25_000_000, ImageSourceLimit::pixels());
    }

    public function test_the_defaults_move_with_the_upload_rules(): void
    {
        // Raising what an upload may be must not leave the stored copy refused by the processor.
        config([
            'openpne.images.max_source_kilobytes' => 0,
            'openpne.images.max_source_pixels' => 0,
            'openpne.images.max_upload_kilobytes' => 30720,
            'openpne.images.max_upload_dimension' => 6000,
        ]);

        $this->assertSame(30720 * 1024, ImageSourceLimit::bytes());
        $this->assertSame(36_000_000, ImageSourceLimit::pixels());
    }

    public function test_a_blank_side_limit_does_not_collapse_the_pixel_cap_to_nothing(): void
    {
        config(['openpne.images.max_source_pixels' => 0, 'openpne.images.max_upload_dimension' => '']);

        $this->assertSame(25_000_000, ImageSourceLimit::pixels());
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
}
