<?php

namespace App\Features\CommunityEvent\Queries;

use App\Features\CommunityTopic\TopicReadAccess;
use App\Models\CommunityEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The most recently active events across every public community (topic_read_access = Everyone), for
 * the home "latest events across the SNS" gadget. The event counterpart of RecentPublicCommunityTopics:
 * viewer-independent and applies no block filter (OpenPNE 3 parity).
 */
class RecentPublicCommunityEvents
{
    public const LIMIT = 5;

    /** @return Collection<int, CommunityEvent> */
    public function __invoke(int $limit = self::LIMIT): Collection
    {
        return CommunityEvent::query()
            ->whereHas('community', fn (Builder $q) => $q->where('topic_read_access', TopicReadAccess::Everyone))
            ->withCount('comments')
            ->with('community')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
