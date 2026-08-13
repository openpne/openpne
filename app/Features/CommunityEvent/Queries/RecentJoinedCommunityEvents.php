<?php

namespace App\Features\CommunityEvent\Queries;

use App\Models\CommunityEvent;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Support\Collection;

/**
 * The most recently active events across the groups a member has confirmed-joined, for the
 * home dashboard's community activity digest. The event counterpart of RecentJoinedGroupTopics
 * (same ordering and membership scope).
 */
class RecentJoinedCommunityEvents
{
    public const LIMIT = 5;

    /** @return Collection<int, CommunityEvent> */
    public function __invoke(Member $viewer, int $limit = self::LIMIT): Collection
    {
        return CommunityEvent::query()
            ->whereIn('community_id', GroupMember::query()
                ->where('member_id', $viewer->getKey())
                ->select('group_id'))
            ->withCount(['comments', 'participants'])
            ->with('community.image')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
