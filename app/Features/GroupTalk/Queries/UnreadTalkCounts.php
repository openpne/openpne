<?php

namespace App\Features\GroupTalk\Queries;

use App\Features\GroupTalk\UnreadTalkScope;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * How many unread messages the member has in each group they belong to, and whether they have
 * muted it — the numbers the group list shows per row.
 *
 * One query for every membership, not one per group: the count is a correlated subquery over
 * `group_messages`, so a member in fifty groups still costs a single round trip.
 */
class UnreadTalkCounts
{
    /** @return array<int, array{count: int, muted: bool}> keyed by group id */
    public function __invoke(Member $viewer): array
    {
        $viewerId = (int) $viewer->getKey();

        $unread = UnreadTalkScope::correlate(
            DB::table('group_messages')->selectRaw('count(*)'),
            'group_members',
            $viewerId,
        );

        $rows = DB::table('group_members')
            ->where('member_id', $viewerId)
            ->select('group_id', 'is_talk_muted')
            ->selectSub($unread, 'unread')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row->group_id] = [
                'count' => (int) $row->unread,
                'muted' => (bool) $row->is_talk_muted,
            ];
        }

        return $counts;
    }
}
