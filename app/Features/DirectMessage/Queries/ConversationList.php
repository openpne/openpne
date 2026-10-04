<?php

namespace App\Features\DirectMessage\Queries;

use App\Features\DirectMessage\ConversationSummary;
use App\Models\DirectMessage;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The order is decided in SQL, before the page is cut, and `ConversationScope`'s two arms are
 * restated here as the two halves of a union the scope's builder cannot be reused in
 * (`docs/internals/direct-messages.md`, "The conversation list").
 */
class ConversationList
{
    /** Conversations per page (OpenPNE 3 app_message_pagenatesize, the mailbox's own size). */
    public const PER_PAGE = 20;

    /** @return LengthAwarePaginator<int, ConversationSummary> */
    public function __invoke(Member $viewer, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $page = DB::query()
            ->fromSub($this->heads((int) $viewer->getKey()), 'heads')
            ->select(['heads.counterpart_id', 'heads.latest_at', 'heads.latest_id', 'heads.unread_count'])
            ->where('heads.recency_rank', 1)
            // An upgraded multi-recipient send is the shared latest of every conversation it landed
            // in, so the counterpart is the final tie-break an offset page needs to not duplicate or
            // drop a row.
            ->orderByDesc('latest_at')
            ->orderByDesc('latest_id')
            // A CASE because where an engine collates NULL in a descending sort is not portable.
            ->orderByRaw('case when heads.counterpart_id is null then 1 else 0 end')
            ->orderByDesc('heads.counterpart_id')
            ->paginate($perPage);

        return $page->setCollection($this->summaries($page->getCollection()));
    }

    /**
     * Every visible message once per counterpart, ranked newest-first within the counterpart and
     * carrying the counterpart's unread total; NULL partitions with NULL, which is what collapses
     * the withdrawn bucket into one row.
     */
    private function heads(int $viewerId): Builder
    {
        return DB::query()
            ->fromSub($this->visible($viewerId), 'visible')
            ->select(['visible.counterpart_id', 'visible.created_at as latest_at', 'visible.id as latest_id'])
            ->selectRaw('row_number() over (partition by visible.counterpart_id order by visible.created_at desc, visible.id desc) as recency_rank')
            ->selectRaw('sum(visible.unread) over (partition by visible.counterpart_id) as unread_count');
    }

    /**
     * Per-side visibility and nothing else, so a conversation the viewer has emptied from their side
     * leaves the list while staying whole in the other's.
     */
    private function visible(int $viewerId): Builder
    {
        $sent = DB::table('direct_message_recipients as delivery')
            ->join('direct_messages as message', 'message.id', '=', 'delivery.direct_message_id')
            ->where('message.sender_id', $viewerId)
            ->where('message.is_draft', false)
            ->whereNull('message.sender_deleted_at')
            ->whereNull('message.sender_purged_at')
            ->select(['delivery.recipient_id as counterpart_id', 'message.created_at', 'message.id'])
            ->selectRaw('0 as unread');

        $received = DB::table('direct_message_recipients as delivery')
            ->join('direct_messages as message', 'message.id', '=', 'delivery.direct_message_id')
            ->where('delivery.recipient_id', $viewerId)
            ->whereNull('delivery.recipient_deleted_at')
            ->whereNull('delivery.recipient_purged_at')
            ->where('message.is_draft', false)
            ->select(['message.sender_id as counterpart_id', 'message.created_at', 'message.id'])
            ->selectRaw('case when delivery.read_at is null then 1 else 0 end as unread');

        return $sent->unionAll($received);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return Collection<int, ConversationSummary>
     */
    private function summaries(Collection $rows): Collection
    {
        $memberIds = $rows->pluck('counterpart_id')->filter()->map(static fn ($id): int => (int) $id)->all();
        $members = $memberIds === []
            ? new Collection
            : Member::query()->whereIn('id', $memberIds)->with('avatar.file')->get()->keyBy('id');
        // Whether there are pictures, not how many: the preview's stand-in never counts them.
        $messageIds = $rows->pluck('latest_id')->map(static fn ($id): int => (int) $id)->all();
        $messages = $messageIds === []
            ? new Collection
            : DirectMessage::query()->whereIn('id', $messageIds)->withExists('files')->get()->keyBy('id');

        return $rows->map(fn (object $row): ConversationSummary => new ConversationSummary(
            counterpart: $row->counterpart_id === null ? null : $members[(int) $row->counterpart_id],
            latest: $messages[(int) $row->latest_id],
            unread: (int) $row->unread_count,
        ));
    }
}
