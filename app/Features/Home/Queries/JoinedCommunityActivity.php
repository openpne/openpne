<?php

namespace App\Features\Home\Queries;

use App\Features\CommunityEvent\Queries\RecentJoinedCommunityEvents;
use App\Features\CommunityTopic\Queries\RecentJoinedCommunityTopics;
use App\Models\CommunityEvent;
use App\Models\CommunityTopic;
use App\Models\Member;
use Illuminate\Support\Collection;

/**
 * The home dashboard's cross-community activity digest: the viewer's joined communities' most recent
 * topics and events, merged newest-first. Each source is queried for $limit rows, so the merged top
 * $limit is correct across both. Also backs the /m/community/recent page with a larger cap.
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
        // toBase() drops the Eloquent-collection PK dedupe: a topic and an event can share a numeric
        // id, and a keyed merge would silently collapse them into one row.
        return ($this->topics)($viewer, $limit)->toBase()
            ->concat(($this->events)($viewer, $limit))
            ->sortByDesc(fn (CommunityTopic|CommunityEvent $row): int => $row->updated_at->getTimestamp())
            ->take($limit)
            ->values();
    }
}
