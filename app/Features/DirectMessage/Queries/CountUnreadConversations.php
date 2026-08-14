<?php

namespace App\Features\DirectMessage\Queries;

use App\Models\Member;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Modern badge: how many of the viewer's conversations have something new — **conversations, not
 * messages**, the same reading the group badge takes (CountGroupsWithUnreadTalk). A message count is
 * dominated by whoever wrote most, which says nothing about where to go next; the number of people
 * waiting for an answer is what the badge is for.
 *
 * Unread is the received arm and nothing else, exactly as ConversationUnreadSnapshot reads it: a
 * message written by the counterpart whose live receipt the viewer has not opened.
 *
 * The Classic home caution keeps counting messages (CountUnreadDirectMessages) — it links into the
 * mailbox, where the number it announces is the number of rows waiting.
 */
class CountUnreadConversations
{
    public function __invoke(Member $viewer): int
    {
        // One statement: COUNT(DISTINCT) skips the null sender, so the withdrawn bucket — every
        // departed member's messages, read as one conversation — is added back as the single row it
        // is. MAX over no rows is null, which is the zero this wants.
        $row = $this->unread((int) $viewer->getKey())
            ->selectRaw('count(distinct conversation.sender_id) as named')
            ->selectRaw('max(case when conversation.sender_id is null then 1 else 0 end) as withdrawn')
            ->first();

        return (int) $row->named + (int) $row->withdrawn;
    }

    /** Delivered messages the viewer holds an unopened live receipt for. */
    private function unread(int $viewerId): Builder
    {
        return DB::table('direct_messages as conversation')
            ->where('conversation.is_draft', false)
            ->whereExists(fn (Builder $receipt) => $receipt
                ->select(DB::raw('1'))
                ->from('direct_message_recipients as delivery')
                ->whereColumn('delivery.direct_message_id', 'conversation.id')
                ->where('delivery.recipient_id', $viewerId)
                ->whereNull('delivery.read_at')
                ->whereNull('delivery.recipient_deleted_at')
                ->whereNull('delivery.recipient_purged_at'));
    }
}
