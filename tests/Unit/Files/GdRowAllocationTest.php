<?php

declare(strict_types=1);

namespace Tests\Unit\Files;

use App\Files\ImageIntake;
use PHPUnit\Framework\TestCase;

/**
 * ImageIntake::gdPixelCost() models how this PHP's GD takes an image's memory; a PHP or libgd upgrade
 * that changes it fails here rather than in a worker.
 */
class GdRowAllocationTest extends TestCase
{
    private const HEIGHT = 2000;

    public function test_the_bundled_libgd_takes_each_row_from_memory_limit_in_whole_4kb_pages(): void
    {
        if (! (defined('GD_BUNDLED') && GD_BUNDLED)) {
            $this->markTestSkipped('This PHP uses an external libgd.');
        }

        foreach ([1024, 1025, 2048, 3073] as $width) {
            $this->assertEqualsWithDelta(4 * ImageIntake::gdPixelCost($width, self::HEIGHT), $this->zendCost($width), 0.01 * 4 * ImageIntake::gdPixelCost($width, self::HEIGHT), "width {$width}");
        }
    }

    public function test_an_external_libgd_takes_nothing_from_memory_limit(): void
    {
        if (defined('GD_BUNDLED') && GD_BUNDLED) {
            $this->markTestSkipped('This PHP uses the bundled libgd.');
        }

        $this->assertLessThan(64 * 1024, $this->zendCost(3073));
    }

    private function zendCost(int $width): int
    {
        gc_collect_cycles();
        $before = memory_get_usage();
        $gd = imagecreatetruecolor($width, self::HEIGHT);
        $cost = memory_get_usage() - $before;
        unset($gd);

        return $cost;
    }
}
