<?php

declare(strict_types=1);

namespace Tests\Unit\Home;

use App\Features\Home\Data\HomeIssueMonth;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HomeIssueMonthTest extends TestCase
{
    public function test_a_month_runs_from_its_first_day_to_its_last(): void
    {
        $february = new HomeIssueMonth(2028, 2);

        $this->assertSame('2028-02-01', $february->first()->toDateString());
        $this->assertSame('2028-02-29', $february->last()->toDateString());
        $this->assertSame('/home/2028/02', $february->href());
    }

    public function test_a_date_names_the_month_it_falls_in(): void
    {
        $month = HomeIssueMonth::of(CarbonImmutable::parse('2026-09-30'));

        $this->assertSame([2026, 9], [$month->year, $month->month]);
    }

    #[DataProvider('monthsNoCalendarHas')]
    public function test_a_month_no_calendar_has_is_refused(int $month): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HomeIssueMonth(2026, $month);
    }

    /** @return array<string, array{int}> */
    public static function monthsNoCalendarHas(): array
    {
        return ['zero' => [0], 'thirteen' => [13], 'negative' => [-1]];
    }
}
