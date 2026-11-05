<?php

namespace App\Features\GroupTopic\Queries;

use App\Models\GroupMember;
use App\Models\GroupTopic;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The viewer's joined groups, as OpenPNE 3's getRecentlyTopicListPager scoped it, so neither the
 * read-access gate nor a block filter applies.
 */
class ListJoinedGroupTopics
{
    public const PER_PAGE = 50;

    /** @return LengthAwarePaginator<int, GroupTopic> */
    public function __invoke(Member $viewer, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        // The ids first and the counts after: MySQL evaluates a projected subquery for every
        // candidate row before the sort cuts to the page (docs/internals/ordering.md, "Counts after the cut").
        $page = GroupTopic::query()
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

        return $page->setCollection(GroupTopic::query()
            ->whereIn('id', $page->getCollection()->modelKeys())
            ->withCount('comments')
            ->with('group')
            ->orderByDesc('bumped_at')
            ->orderByDesc('id')
            ->get());
    }
}
