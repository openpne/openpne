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
 * Groups founded in the window, newest first.
 *
 * Every group, whatever its read access. `topic_read_access` gates a group's contents, and this
 * section shows none of them — the group page itself is open to any signed-in member
 * (GroupPolicy::view is unconditionally true), so a new MembersOnly group is a door to knock on
 * rather than something withheld.
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
