<?php

declare(strict_types=1);

namespace App\Features\Home\Queries;

use App\Features\Home\Actions\PublishHomeIssue;
use App\Models\HomeIssue;
use Carbon\CarbonImmutable;

/**
 * The day is validated before it is looked for, because a route pattern admits shapes a calendar
 * does not: `/home/2026/02/30` is a well-formed URL naming a day that never happened, and it must
 * read as nothing rather than as a query.
 */
final class FindHomeIssueByDate
{
    public function __invoke(int $year, int $month, int $day): ?HomeIssue
    {
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        $date = CarbonImmutable::create($year, $month, $day);

        // `whereDate` for the reason the publisher uses it ({@see PublishHomeIssue::publishedOn}):
        // the two engines do not hold the column alike, and a literal that matched one would miss
        // the other.
        return HomeIssue::query()->whereDate('issue_date', $date->toDateString())->first();
    }
}
