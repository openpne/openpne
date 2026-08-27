<?php

declare(strict_types=1);

namespace App\Features\Home\Queries;

use App\Models\HomeIssue;

/**
 * The issues either side of one, for the pager at the foot of the page.
 *
 * By `issue_date` and not by number: the day is what a reader is moving through, and a repair that
 * renumbers the archive must not reorder it. The column is unique, so the neighbour on each side is
 * settled without a tiebreak.
 */
final class AdjacentHomeIssues
{
    /** @return array{previous: ?HomeIssue, next: ?HomeIssue} */
    public function __invoke(HomeIssue $issue): array
    {
        return [
            'previous' => HomeIssue::query()
                ->where('issue_date', '<', $issue->issue_date)
                ->orderByDesc('issue_date')
                ->first(),
            'next' => HomeIssue::query()
                ->where('issue_date', '>', $issue->issue_date)
                ->orderBy('issue_date')
                ->first(),
        ];
    }
}
