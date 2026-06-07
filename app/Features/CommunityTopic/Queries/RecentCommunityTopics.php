<?php

namespace App\Features\CommunityTopic\Queries;

use App\Models\Community;
use App\Models\CommunityTopic;
use Illuminate\Support\Collection;

/**
 * The most recently active topics of a community, for the "recent topics" box on the community
 * home (OpenPNE 3 community/home). Same ordering as the board, capped at a few rows.
 */
class RecentCommunityTopics
{
    public const LIMIT = 5;

    /** @return Collection<int, CommunityTopic> */
    public function __invoke(Community $community, int $limit = self::LIMIT): Collection
    {
        return $community->topics()
            ->withCount('comments')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
