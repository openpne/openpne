<?php

declare(strict_types=1);

namespace App\Features\Home\Queries;

use App\Features\Home\Data\HomeIssueMonth;
use App\Models\HomeIssue;
use Carbon\CarbonImmutable;

/**
 * How many issues each month holds, newest month first. Counted here rather than by the engine: the
 * rows are one per published day, and the two engines do not name a year-month alike.
 */
final class ListHomeIssueMonths
{
    /** @return list<array{year: int, month: int, count: int, href: string}> */
    public function __invoke(): array
    {
        $months = [];

        foreach (HomeIssue::query()->orderByDesc('issue_date')->toBase()->pluck('issue_date') as $date) {
            $month = HomeIssueMonth::of(CarbonImmutable::parse((string) $date));
            $key = $month->href();

            $months[$key] ??= ['year' => $month->year, 'month' => $month->month, 'count' => 0, 'href' => $key];
            $months[$key]['count']++;
        }

        return array_values($months);
    }
}
