<?php

namespace App\Features\GroupTopic\Queries;

use App\Models\GroupMember;
use App\Models\GroupTopic;
use App\Models\Member;
use Illuminate\Support\Collection;

/**
 * The most recently active topics across the groups a member has confirmed-joined, for the
 * home dashboard's group activity digest. Same ordering as RecentGroupTopics / the board
 * (updated_at, bumped when a comment or edit lands), scoped by membership instead of one group.
 * No block filter — a joined group's board is visible to its members (the board itself applies
 * none), so the digest matches what the member already sees there.
 */
class RecentJoinedGroupTopics
{
    public const LIMIT = 5;

    /** @return Collection<int, GroupTopic> */
    public function __invoke(Member $viewer, int $limit = self::LIMIT): Collection
    {
        return GroupTopic::query()
            ->whereIn('group_id', GroupMember::query()
                ->where('member_id', $viewer->getKey())
                ->select('group_id'))
            ->withCount('comments')
            ->with('group.image')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
