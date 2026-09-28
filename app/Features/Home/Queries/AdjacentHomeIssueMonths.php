<?php

declare(strict_types=1);

namespace App\Features\Home\Queries;

use App\Features\Home\Data\HomeIssueMonth;
use App\Models\HomeIssue;

/** The nearest month either side that has an issue, so a pager never lands on an empty one. */
final class AdjacentHomeIssueMonths
{
    /** @return array{previous: ?HomeIssueMonth, next: ?HomeIssueMonth} */
    public function __invoke(HomeIssueMonth $month): array
    {
        $before = HomeIssue::query()
            ->whereDate('issue_date', '<', $month->first()->toDateString())
            ->orderByDesc('issue_date')
            ->first();

        $after = HomeIssue::query()
            ->whereDate('issue_date', '>', $month->last()->toDateString())
            ->orderBy('issue_date')
            ->first();

        return [
            'previous' => $before === null ? null : HomeIssueMonth::of($before->issue_date),
            'next' => $after === null ? null : HomeIssueMonth::of($after->issue_date),
        ];
    }
}
