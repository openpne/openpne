<?php

namespace App\Features\Home\Queries;

use App\Features\GroupEvent\Queries\RecentJoinedGroupEvents;
use App\Features\GroupTopic\Queries\RecentJoinedGroupTopics;
use App\Models\GroupEvent;
use App\Models\GroupTopic;
use App\Models\Member;
use App\Support\Feature;
use Illuminate\Support\Collection;

/**
 * The viewer's joined groups' most recent topics and events, merged newest-first: each source is
 * queried for $limit rows, so the merged top $limit is correct across both. Each half follows its
 * own feature unit here rather than at the call sites, this being the aggregate that reaches across
 * units (docs/internals/feature-toggles.md, "Modern surface").
 */
class JoinedGroupActivity
{
    public const LIMIT = 5;

    public function __construct(
        private readonly RecentJoinedGroupTopics $topics,
        private readonly RecentJoinedGroupEvents $events,
    ) {}

    /** @return Collection<int, GroupTopic|GroupEvent> */
    public function __invoke(Member $viewer, int $limit = self::LIMIT): Collection
    {
        $topics = Feature::GroupTopic->enabled() ? ($this->topics)($viewer, $limit) : collect();
        $events = Feature::GroupEvent->enabled() ? ($this->events)($viewer, $limit) : collect();

        // toBase() drops the Eloquent-collection PK dedupe: a topic and an event can share a numeric
        // id, and a keyed merge would silently collapse them into one row.
        return $topics->toBase()
            ->concat($events)
            ->sortByDesc(fn (GroupTopic|GroupEvent $row): int => $row->updated_at->getTimestamp())
            ->take($limit)
            ->values();
    }
}
