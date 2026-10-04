<?php

namespace App\Features\GroupEvent\Queries;

use App\Models\GroupEvent;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Support\Collection;

class RecentJoinedGroupEvents
{
    public const LIMIT = 5;

    /** @return Collection<int, GroupEvent> */
    public function __invoke(Member $viewer, int $limit = self::LIMIT): Collection
    {
        // The ids first and the counts after: MySQL evaluates a projected subquery for every
        // candidate row before the sort cuts to the LIMIT (docs/internals/ordering.md, "Counts after the cut").
        $ids = GroupEvent::query()
            ->whereIn('group_id', GroupMember::query()
                ->where('member_id', $viewer->getKey())
                ->select('group_id'))
            ->orderByDesc('bumped_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('id');
        if ($ids->isEmpty()) {
            return $ids;
        }

        return GroupEvent::query()
            ->whereIn('id', $ids)
            ->withCount(['comments', 'participants'])
            ->with('group.image')
            ->orderByDesc('bumped_at')
            ->orderByDesc('id')
            ->get();
    }
}
