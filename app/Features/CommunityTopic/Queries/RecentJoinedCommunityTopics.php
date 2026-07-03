<?php

namespace App\Features\CommunityTopic\Queries;

use App\Models\CommunityMember;
use App\Models\CommunityTopic;
use App\Models\Member;
use Illuminate\Support\Collection;

/**
 * The most recently active topics across the communities a member has confirmed-joined, for the
 * home dashboard's community activity digest. Same ordering as RecentCommunityTopics / the board
 * (updated_at, bumped when a comment or edit lands), scoped by membership instead of one community.
 * No block filter — a joined community's board is visible to its members (the board itself applies
 * none), so the digest matches what the member already sees there.
 */
class RecentJoinedCommunityTopics
{
    public const LIMIT = 5;

    /** @return Collection<int, CommunityTopic> */
    public function __invoke(Member $viewer, int $limit = self::LIMIT): Collection
    {
        return CommunityTopic::query()
            ->whereIn('community_id', CommunityMember::query()
                ->where('member_id', $viewer->getKey())
                ->select('community_id'))
            ->withCount('comments')
            ->with('community')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
