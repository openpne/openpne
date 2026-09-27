<?php

declare(strict_types=1);

namespace App\Features\Home\Queries;

use App\Features\Home\Actions\PublishHomeIssue;
use App\Features\Home\Data\HomeIssueMonth;
use App\Models\HomeIssue;
use Illuminate\Database\Eloquent\Collection;

/**
 * The issues dated in a month, newest first. `whereDate` for the reason the publisher uses it
 * ({@see PublishHomeIssue::publishedOn}).
 */
final class ListHomeIssuesInMonth
{
    /** @return Collection<int, HomeIssue> */
    public function __invoke(HomeIssueMonth $month): Collection
    {
        return HomeIssue::query()
            ->whereDate('issue_date', '>=', $month->first()->toDateString())
            ->whereDate('issue_date', '<=', $month->last()->toDateString())
            ->orderByDesc('issue_date')
            ->get();
    }
}
