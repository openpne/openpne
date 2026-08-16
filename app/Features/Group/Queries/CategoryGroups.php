<?php

namespace App\Features\Group\Queries;

use App\Models\Group;
use Illuminate\Support\Collection;

/**
 * The other groups filed under one group's category, biggest first — a way on from a group to the
 * ones next to it.
 *
 * A projection of a capability that already exists: any authenticated member may view any group
 * (GroupPolicy::view is unconditionally true), and a group's category is on the group page and in
 * the search filter. So this takes no viewer: the rows must be the same for a member of the group,
 * a stranger to it, and someone whose join request is pending, and no visibility rule of its own may
 * be introduced here — a difference between two viewers would be a new fact about them, told by a
 * list that is not about them.
 */
class CategoryGroups
{
    /** Cards in the section: two rows of three in the grid it feeds. */
    public const LIMIT = 6;

    /**
     * @return Collection<int, Group> empty when $group is filed under no category
     */
    public function take(Group $group, int $limit = self::LIMIT): Collection
    {
        if ($group->group_category_id === null) {
            return collect();
        }

        return Group::query()
            ->where('group_category_id', $group->group_category_id)
            ->whereKeyNot($group->getKey())
            ->with('image')
            ->withCount('members')
            ->orderByDesc('members_count')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
