<?php

namespace App\Features\Diary;

use Carbon\CarbonImmutable;

/**
 * A month grid for the diary sidemenu calendar (OpenPNE 3 Calendar_Month_Weekdays, Sunday-first).
 * Weeks are rows of seven cells; a null cell is padding from an adjacent month. Previous/next
 * month are always available — OpenPNE 3's calendar nav is unbounded.
 */
final class DiaryCalendar
{
    /** @param  list<list<?int>>  $weeks */
    private function __construct(
        public readonly int $year,
        public readonly int $month,
        public readonly array $weeks,
    ) {}

    public static function forMonth(int $year, int $month): self
    {
        $first = CarbonImmutable::createFromDate($year, $month, 1)->startOfDay();

        // Carbon dayOfWeek: Sunday=0 … Saturday=6, so it is the count of leading padding cells.
        $cells = array_fill(0, $first->dayOfWeek, null);
        for ($day = 1; $day <= $first->daysInMonth; $day++) {
            $cells[] = $day;
        }
        while (count($cells) % 7 !== 0) {
            $cells[] = null;
        }

        return new self($year, $month, array_chunk($cells, 7));
    }

    /** @return array{year: int, month: int} */
    public function previousMonth(): array
    {
        return self::yearMonth(CarbonImmutable::createFromDate($this->year, $this->month, 1)->subMonth());
    }

    /** @return array{year: int, month: int} */
    public function nextMonth(): array
    {
        return self::yearMonth(CarbonImmutable::createFromDate($this->year, $this->month, 1)->addMonth());
    }

    /** Locale-neutral heading, matching ArchivePeriod's `Y-m` archive label. */
    public function label(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    /** @return array{year: int, month: int} */
    private static function yearMonth(CarbonImmutable $date): array
    {
        return ['year' => $date->year, 'month' => $date->month];
    }
}
