<?php

namespace Tests\Unit\Diary;

use App\Features\Diary\DiaryCalendar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class DiaryCalendarTest extends TestCase
{
    public function test_weeks_are_seven_cells_each(): void
    {
        foreach (DiaryCalendar::forMonth(2026, 6)->weeks as $week) {
            $this->assertCount(7, $week);
        }
    }

    public function test_cells_hold_each_day_once_in_order(): void
    {
        $days = array_values(array_filter(
            array_merge(...DiaryCalendar::forMonth(2026, 6)->weeks),
            fn ($cell) => $cell !== null,
        ));

        $this->assertSame(range(1, 30), $days); // June has 30 days
    }

    public function test_the_first_day_sits_in_its_weekday_column(): void
    {
        // June 1 2026 is a Monday; the Sunday-first grid puts it in column index 1.
        $this->assertSame(1, CarbonImmutable::createFromDate(2026, 6, 1)->dayOfWeek);

        $first = DiaryCalendar::forMonth(2026, 6)->weeks[0];
        $this->assertNull($first[0]); // leading padding (Sunday)
        $this->assertSame(1, $first[1]);
    }

    public function test_padding_only_brackets_the_month(): void
    {
        $cells = array_merge(...DiaryCalendar::forMonth(2026, 6)->weeks);

        $real = array_keys(array_filter($cells, fn ($cell) => $cell !== null));
        $firstReal = $real[0];
        $lastReal = $real[count($real) - 1];

        for ($i = 0; $i < count($cells); $i++) {
            if ($i >= $firstReal && $i <= $lastReal) {
                $this->assertNotNull($cells[$i]); // no gaps within the month span
            } else {
                $this->assertNull($cells[$i]);     // only leading/trailing padding
            }
        }
    }

    public function test_previous_and_next_month_cross_year_boundaries(): void
    {
        $this->assertSame(['year' => 2025, 'month' => 12], DiaryCalendar::forMonth(2026, 1)->previousMonth());
        $this->assertSame(['year' => 2027, 'month' => 1], DiaryCalendar::forMonth(2026, 12)->nextMonth());
    }

    public function test_label_is_the_locale_neutral_year_month(): void
    {
        $this->assertSame('2026-06', DiaryCalendar::forMonth(2026, 6)->label());
    }
}
