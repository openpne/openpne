<?php

namespace App\Features\Home\Queries;

use App\Features\CommunityEvent\Queries\RecentJoinedCommunityEvents;
use App\Features\CommunityTopic\Queries\RecentJoinedCommunityTopics;
use App\Models\CommunityEvent;
use App\Models\CommunityTopic;
use App\Models\Member;
use App\Support\Feature;
use Illuminate\Support\Collection;

/**
 * The home dashboard's cross-community activity digest: the viewer's joined communities' most recent
 * topics and events, merged newest-first. Each source is queried for $limit rows, so the merged top
 * $limit is correct across both. Also backs the /community/recent page with a larger cap.
 *
 * Each half follows its own feature unit here rather than at the call sites: this is the aggregate
 * that reaches across features, so the constraint belongs in the query (feature-modules.md invariant 2).
 */
class JoinedCommunityActivity
{
    public const LIMIT = 5;

    public function __construct(
        private readonly RecentJoinedCommunityTopics $topics,
        private readonly RecentJoinedCommunityEvents $events,
    ) {}

    /** @return Collection<int, CommunityTopic|CommunityEvent> */
    public function __invoke(Member $viewer, int $limit = self::LIMIT): Collection
    {
        $topics = Feature::CommunityTopic->enabled() ? ($this->topics)($viewer, $limit) : collect();
        $events = Feature::CommunityEvent->enabled() ? ($this->events)($viewer, $limit) : collect();

        // toBase() drops the Eloquent-collection PK dedupe: a topic and an event can share a numeric
        // id, and a keyed merge would silently collapse them into one row.
        return $topics->toBase()
            ->concat($events)
            ->sortByDesc(fn (CommunityTopic|CommunityEvent $row): int => $row->updated_at->getTimestamp())
            ->take($limit)
            ->values();
    }
}
