<?php

namespace App\Features\CommunityTopic\Queries;

use App\Models\CommunityTopic;
use App\Models\Group;
use Illuminate\Support\Collection;

/**
 * The most recently active topics of a community, for the "recent topics" box on the community
 * home. Same ordering as the board, capped at a few rows.
 */
class RecentCommunityTopics
{
    public const LIMIT = 5;

    /** @return Collection<int, CommunityTopic> */
    public function __invoke(Group $group, int $limit = self::LIMIT): Collection
    {
        return $group->topics()
            ->withCount('comments')
            ->with('member.avatar.file')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
