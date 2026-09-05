<?php

namespace App\Features\Group\Queries;

use App\Models\Group;
use Illuminate\Support\Collection;

/**
 * Takes no viewer deliberately: any authenticated member may view any group, so the rows must read
 * the same to a member, a stranger and a pending applicant. No visibility rule of its own may be
 * introduced here.
 */
class CategoryGroups
{
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
