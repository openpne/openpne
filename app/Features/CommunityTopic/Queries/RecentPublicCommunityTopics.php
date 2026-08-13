<?php

namespace App\Features\CommunityTopic\Queries;

use App\Features\CommunityTopic\TopicReadAccess;
use App\Models\CommunityTopic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The most recently active topics across every public community (topic_read_access = Everyone,
 * which GroupUpgrade maps OpenPNE 3's public groups to), for the home "latest topics
 * across the SNS" gadget. Viewer-independent and applies no block filter — OpenPNE 3
 * getPublicCommunityIdList showed the same public feed to every member.
 */
class RecentPublicCommunityTopics
{
    public const LIMIT = 5;

    /** @return Collection<int, CommunityTopic> */
    public function __invoke(int $limit = self::LIMIT): Collection
    {
        return CommunityTopic::query()
            ->whereHas('community', fn (Builder $q) => $q->where('topic_read_access', TopicReadAccess::Everyone))
            ->withCount('comments')
            ->with('community')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
