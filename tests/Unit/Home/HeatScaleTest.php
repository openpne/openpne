<?php

declare(strict_types=1);

namespace Tests\Unit\Home;

use App\Features\Home\HeatScale;
use PHPUnit\Framework\TestCase;

class HeatScaleTest extends TestCase
{
    public function test_a_month_of_one_day_is_drawn_at_the_lowest_level(): void
    {
        $this->assertSame([7 => 1], HeatScale::levels([7 => 120]));
    }

    public function test_a_month_of_equal_days_is_drawn_at_the_lowest_level_throughout(): void
    {
        $this->assertSame([1 => 1, 2 => 1, 3 => 1], HeatScale::levels([1 => 5, 2 => 5, 3 => 5]));
    }

    public function test_four_days_that_differ_take_the_four_levels(): void
    {
        $this->assertSame(
            [1 => 3, 2 => 1, 3 => 4, 4 => 2],
            HeatScale::levels([1 => 30, 2 => 10, 3 => 40, 4 => 20]),
        );
    }

    /** A share of the busiest would draw 1, 2 and 3 alike beside 1000. */
    public function test_one_very_busy_day_does_not_flatten_the_rest(): void
    {
        $this->assertSame(
            [1 => 1, 2 => 2, 3 => 3, 4 => 4],
            HeatScale::levels([1 => 1, 2 => 2, 3 => 3, 4 => 1000]),
        );
    }

    /** Counted among the ranked, a day with nothing left would lift the only busy one to 3. */
    public function test_a_day_with_nothing_left_is_not_ranked_against(): void
    {
        $this->assertSame([1 => 0, 2 => 1], HeatScale::levels([1 => 0, 2 => 9]));
    }

    public function test_a_tie_takes_the_lower_level(): void
    {
        $this->assertSame(
            [1 => 1, 2 => 1, 3 => 3, 4 => 3],
            HeatScale::levels([1 => 2, 2 => 2, 3 => 8, 4 => 8]),
        );
    }

    public function test_more_days_than_levels_stop_at_the_top_level(): void
    {
        $levels = HeatScale::levels(array_combine(range(1, 31), range(1, 31)));

        $this->assertSame(1, $levels[1]);
        $this->assertSame(4, $levels[31]);
        $this->assertSame([1, 2, 3, 4], array_values(array_unique($levels)));
    }

    public function test_no_days_are_no_levels(): void
    {
        $this->assertSame([], HeatScale::levels([]));
    }
}
