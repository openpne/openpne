<?php

namespace App\Features\GroupTopic\Queries;

use App\Features\GroupTopic\TopicReadAccess;
use App\Models\GroupTopic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The most recently active topics across every public group (topic_read_access = Everyone,
 * which GroupUpgrade maps OpenPNE 3's public groups to), for the home "latest topics
 * across the SNS" gadget. Viewer-independent and applies no block filter — OpenPNE 3
 * getPublicCommunityIdList showed the same public feed to every member.
 */
class RecentPublicGroupTopics
{
    public const LIMIT = 5;

    /** @return Collection<int, GroupTopic> */
    public function __invoke(int $limit = self::LIMIT): Collection
    {
        return GroupTopic::query()
            ->whereHas('group', fn (Builder $q) => $q->where('topic_read_access', TopicReadAccess::Everyone))
            ->withCount('comments')
            ->with('group')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
