<?php

namespace App\Features\CommunityEvent\Queries;

use App\Models\CommunityEvent;
use App\Models\Group;
use Illuminate\Support\Collection;

/**
 * The most recently active events of a community, for the "recent events" box on the community
 * home. Same ordering as the list, capped at a few rows.
 */
class RecentCommunityEvents
{
    public const LIMIT = 5;

    /** @return Collection<int, CommunityEvent> */
    public function __invoke(Group $group, int $limit = self::LIMIT): Collection
    {
        return $group->events()
            ->withCount(['comments', 'participants'])
            ->with('member.avatar.file')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
