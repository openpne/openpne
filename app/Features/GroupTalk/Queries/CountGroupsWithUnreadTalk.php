<?php

namespace App\Features\GroupTalk\Queries;

use App\Features\GroupTalk\UnreadTalkScope;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * The nav badge: how many of the member's groups have something new — **groups, not messages**.
 *
 * A message count would be dominated by whichever group happens to be busiest, which says nothing
 * about where to go next; the number of rooms with something waiting is what the badge is for.
 * EXISTS rather than a count per group, so a talkative group costs the same as a quiet one.
 *
 * Muted groups are left out: that is what muting is. Their own per-group count keeps showing on the
 * group list (App\Features\GroupTalk\Queries\UnreadTalkCounts) — the member asked for quiet, not to
 * lose track of the conversation.
 */
class CountGroupsWithUnreadTalk
{
    public function __invoke(Member $viewer): int
    {
        $viewerId = (int) $viewer->getKey();

        return DB::table('group_members')
            ->where('member_id', $viewerId)
            ->where('is_talk_muted', false)
            ->whereExists(fn ($messages) => UnreadTalkScope::correlate(
                $messages->selectRaw('1')->from('group_messages'),
                'group_members',
                $viewerId,
            ))
            ->count();
    }
}
