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
 * Members who joined in the window, newest first.
 *
 * No engagement to rank by and none wanted: a face is glanced at, not read, and ordering newcomers
 * by anything they have done yet would rank them against each other on their first day.
 *
 * The lists this sits beside — member search, the right rail's faces — show an AI account and a
 * member an administrator has banned from logging in, so this does too: `is_login_rejected` gates
 * logging in and receiving, not being seen ({@see RandomMembers}).
 * `members.created_at` is nullable and a null is not in any window, so a row from before the column
 * was stamped stays out on the range alone.
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
