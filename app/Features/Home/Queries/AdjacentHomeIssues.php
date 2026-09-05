<?php

declare(strict_types=1);

namespace App\Features\Home\Queries;

use App\Models\HomeIssue;

/**
 * The issues either side of one, by `issue_date` and not by number: the day is what a reader moves
 * through, and a repair that renumbers the archive must not reorder it. The column is unique, so
 * each neighbour is settled without a tiebreak.
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
