<?php

declare(strict_types=1);

namespace App\Features\Home\Queries;

use App\Features\Home\Data\HomeIssueWindow;
use App\Features\Home\Data\PlannedItem;
use App\Features\Home\HomeIssueLedger;
use App\Features\Home\HomeIssueSection;
use App\Features\Member\Queries\RandomMembers;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Members who joined in the window, newest first, with no engagement to rank by. AI accounts and
 * members banned from logging in are in it as they are in the lists this sits beside
 * ({@see RandomMembers}), and a null `members.created_at` is in no window
 * (docs/internals/home-issues.md, "Eligibility: every member may read it").
 */
final class NewcomerCandidates
{
    public function alias(): string
    {
        return (new Member)->getMorphClass();
    }

    /** @return Collection<int, PlannedItem> */
    public function __invoke(HomeIssueWindow $window, int $limit): Collection
    {
        $query = Member::query();
        $window->apply($query, 'members.created_at');
        HomeIssueLedger::excludeFeatured($query, HomeIssueSection::Newcomers, $this->alias(), 'members.id');

        return $query
            ->orderByDesc('members.created_at')
            ->orderByDesc('members.id')
            ->limit($limit)
            ->get()
            ->map(fn (Member $member): PlannedItem => new PlannedItem(
                $this->alias(),
                (int) $member->getKey(),
                0,
                [],
                CarbonImmutable::parse($member->created_at),
            ));
    }
}
