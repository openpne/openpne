<?php

declare(strict_types=1);

namespace App\Features\Home\Queries;

use App\Features\Home\Data\HomeIssueWindow;
use App\Features\Home\Data\PlannedItem;
use App\Features\Home\HomeIssueLedger;
use App\Features\Home\HomeIssueSection;
use App\Models\Group;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Groups founded in the window, newest first — every group, whatever its read access, since this
 * section shows none of a group's contents and the group page itself is open to any signed-in member
 * (docs/internals/home-issues.md, "Eligibility: every member may read it").
 */
final class NewGroupCandidates
{
    public function alias(): string
    {
        return (new Group)->getMorphClass();
    }

    /** @return Collection<int, PlannedItem> */
    public function __invoke(HomeIssueWindow $window, int $limit): Collection
    {
        $query = Group::query()->withCount('members');
        $window->apply($query, 'groups.created_at');
        HomeIssueLedger::excludeFeatured($query, HomeIssueSection::NewGroups, $this->alias(), 'groups.id');

        return $query
            ->orderByDesc('groups.created_at')
            ->orderByDesc('groups.id')
            ->limit($limit)
            ->get()
            ->map(fn (Group $group): PlannedItem => new PlannedItem(
                $this->alias(),
                (int) $group->getKey(),
                0,
                ['members' => (int) $group->members_count],
                CarbonImmutable::parse($group->created_at),
            ));
    }
}
