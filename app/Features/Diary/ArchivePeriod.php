<?php

namespace App\Features\Diary;

use Carbon\CarbonImmutable;

/**
 * A month or a day of a member's archive as a half-open `[start, end)` range, labelled as a
 * locale-neutral numeric date (docs/internals/diary.md, "The archive").
 */
final class ArchivePeriod
{
    private function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $label,
    ) {}

    /** Null for an impossible date (e.g. 2026-02-30), so the caller can 404. */
    public static function fromYearMonthDay(int $year, int $month, ?int $day = null): ?self
    {
        if (! checkdate($month, $day ?? 1, $year)) {
            return null;
        }

        $start = CarbonImmutable::createFromDate($year, $month, $day ?? 1)->startOfDay();

        return $day !== null
            ? new self($start, $start->addDay(), $start->format('Y-m-d'))
            : new self($start, $start->addMonth(), $start->format('Y-m'));
    }
}
