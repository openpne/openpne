<?php

namespace App\Features\GroupEvent\Queries;

use App\Models\GroupEvent;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Support\Collection;

/**
 * The most recently active events across the groups a member has confirmed-joined, for the
 * home dashboard's community activity digest. The event counterpart of RecentJoinedGroupTopics
 * (same ordering and membership scope).
 */
class RecentJoinedGroupEvents
{
    public const LIMIT = 5;

    /** @return Collection<int, GroupEvent> */
    public function __invoke(Member $viewer, int $limit = self::LIMIT): Collection
    {
        return GroupEvent::query()
            ->whereIn('group_id', GroupMember::query()
                ->where('member_id', $viewer->getKey())
                ->select('group_id'))
            ->withCount(['comments', 'participants'])
            ->with('group.image')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
