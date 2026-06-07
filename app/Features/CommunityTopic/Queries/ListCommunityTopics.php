<?php

namespace App\Features\CommunityTopic\Queries;

use App\Models\Community;
use App\Models\CommunityTopic;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * A community's topic board (OpenPNE 3 communityTopic/listCommunity): most recently active first.
 * updated_at is the activity key — a new comment touches it (CreateTopicComment), so a thread with
 * fresh replies sorts above an untouched one. id breaks ties for a stable order.
 */
class ListCommunityTopics
{
    public const PER_PAGE = 20;

    /** @return LengthAwarePaginator<int, CommunityTopic> */
    public function __invoke(Community $community, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $community->topics()
            ->withCount('comments')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
