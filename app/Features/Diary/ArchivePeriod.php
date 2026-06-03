<?php

namespace App\Features\Diary;

use Carbon\CarbonImmutable;

/**
 * A calendar window over a member's diary archive: a whole month, or a single day.
 *
 * The range is half-open [start, end) so it drops straight into a `created_at` filter,
 * matching OpenPNE 3's addDateQuery (`created_at >= begin AND created_at < end`). The label
 * is a locale-neutral numeric date (`2026-03` / `2026-03-15`) shown in the archive heading.
 */
final class ArchivePeriod
{
    private function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $label,
    ) {}

    /** Null for an impossible date (e.g. 2026-02-30), so the caller can 404 like OpenPNE 3. */
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
