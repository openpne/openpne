<?php

namespace Tests\Unit\Diary;

use App\Features\Diary\ArchivePeriod;
use PHPUnit\Framework\TestCase;

class ArchivePeriodTest extends TestCase
{
    public function test_month_period_spans_the_whole_month(): void
    {
        $period = ArchivePeriod::fromYearMonthDay(2026, 3);

        $this->assertNotNull($period);
        $this->assertSame('2026-03-01 00:00:00', $period->start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-01 00:00:00', $period->end->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03', $period->label);
    }

    public function test_day_period_spans_a_single_day(): void
    {
        $period = ArchivePeriod::fromYearMonthDay(2026, 3, 15);

        $this->assertNotNull($period);
        $this->assertSame('2026-03-15 00:00:00', $period->start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-16 00:00:00', $period->end->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-15', $period->label);
    }

    public function test_december_month_rolls_into_the_next_year(): void
    {
        $period = ArchivePeriod::fromYearMonthDay(2026, 12);

        $this->assertSame('2027-01-01 00:00:00', $period->end->format('Y-m-d H:i:s'));
    }

    public function test_impossible_date_is_null(): void
    {
        $this->assertNull(ArchivePeriod::fromYearMonthDay(2026, 2, 30));
        $this->assertNull(ArchivePeriod::fromYearMonthDay(2026, 13, 1));
    }

    public function test_leap_day_validity_follows_the_year(): void
    {
        $this->assertNotNull(ArchivePeriod::fromYearMonthDay(2024, 2, 29));
        $this->assertNull(ArchivePeriod::fromYearMonthDay(2026, 2, 29));
    }
}
