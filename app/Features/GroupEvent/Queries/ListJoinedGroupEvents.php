<?php

namespace App\Features\GroupEvent\Queries;

use App\Models\GroupEvent;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The viewer's joined groups, as OpenPNE 3's getRecentlyEventListPager scoped it, so neither the
 * read-access gate nor a block filter applies.
 */
class ListJoinedGroupEvents
{
    public const PER_PAGE = 50;

    /** @return LengthAwarePaginator<int, GroupEvent> */
    public function __invoke(Member $viewer, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        // The ids first and the counts after: MySQL evaluates a projected subquery for every
        // candidate row before the sort cuts to the page (docs/internals/ordering.md, "Counts after the cut").
        $page = GroupEvent::query()
            ->select('id')
            ->whereIn('group_id', GroupMember::query()
                ->where('member_id', $viewer->getKey())
                ->select('group_id'))
            ->orderByDesc('bumped_at')
            ->orderByDesc('id')
            ->paginate($perPage);
        if ($page->isEmpty()) {
            return $page;
        }

        return $page->setCollection(GroupEvent::query()
            ->whereIn('id', $page->getCollection()->modelKeys())
            ->withCount('comments')
            ->with('group')
            ->orderByDesc('bumped_at')
            ->orderByDesc('id')
            ->get());
    }
}
