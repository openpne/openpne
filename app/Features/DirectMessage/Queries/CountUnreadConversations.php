<?php

namespace App\Features\DirectMessage\Queries;

use App\Models\Member;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** See `docs/internals/direct-messages.md`, "Two counts, two questions". */
class CountUnreadConversations
{
    public function __invoke(Member $viewer): int
    {
        $row = $this->unread((int) $viewer->getKey())
            // COUNT(DISTINCT) skips the null sender, so the second column adds the withdrawn bucket
            // back as the single conversation it is read as.
            ->selectRaw('count(distinct conversation.sender_id) as named')
            // MAX over no rows is null, which is the zero this wants.
            ->selectRaw('max(case when conversation.sender_id is null then 1 else 0 end) as withdrawn')
            ->first();

        return (int) $row->named + (int) $row->withdrawn;
    }

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
