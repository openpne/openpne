<?php

namespace App\Features\GroupEvent\Queries;

use App\Features\GroupTopic\TopicReadAccess;
use App\Models\GroupEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Viewer-independent and applies no block filter (OpenPNE 3 parity). */
class RecentPublicGroupEvents
{
    public const LIMIT = 5;

    /** @return Collection<int, GroupEvent> */
    public function __invoke(int $limit = self::LIMIT): Collection
    {
        return GroupEvent::query()
            ->whereHas('group', fn (Builder $q) => $q->where('topic_read_access', TopicReadAccess::Everyone))
            ->withCount('comments')
            ->with('group')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
