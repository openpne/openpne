<?php

declare(strict_types=1);

namespace Tests\Unit\Files;

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
