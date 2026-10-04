<?php

namespace App\Features\GroupTopic\Queries;

use App\Models\GroupMember;
use App\Models\GroupTopic;
use App\Models\Member;
use Illuminate\Support\Collection;

/**
 * Scoped by membership rather than one group, with the board's ordering. No block filter — a joined
 * group's board applies none, so the digest matches what the member already sees there.
 */
class RecentJoinedGroupTopics
{
    public const LIMIT = 5;

    /** @return Collection<int, GroupTopic> */
    public function __invoke(Member $viewer, int $limit = self::LIMIT): Collection
    {
        // The ids first and the counts after: MySQL evaluates a projected subquery for every
        // candidate row before the sort cuts to the LIMIT (docs/internals/ordering.md, "Counts after the cut").
        $ids = GroupTopic::query()
            ->whereIn('group_id', GroupMember::query()
                ->where('member_id', $viewer->getKey())
                ->select('group_id'))
            ->orderByDesc('bumped_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('id');

        return GroupTopic::query()
            ->whereIn('id', $ids)
            ->withCount('comments')
            ->with('group.image')
            ->orderByDesc('bumped_at')
            ->orderByDesc('id')
            ->get();
    }
}
