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
 * The viewer's conversations, most recently written in first: one row per counterpart, carrying the
 * message it leads with and the viewer's unread.
 *
 * **The order is decided in SQL, before the page is cut** — the room list's rule
 * (docs/internals/group-talk.md), for the same reason: paging the counterparts first and then
 * looking up their newest messages sorts the page rather than the correspondence. So the newest
 * `(created_at, id)` rides along as two correlated subselects, one per column, since the tuple
 * cannot be read in one statement without a row constructor or a lateral join.
 *
 * The counterparts are composed in the FROM clause as a UNION of the two arms — which
 * ConversationScope refuses for reading a conversation, and which is right here because this set is
 * never ordered or sliced: it is deduplicated and nothing else. That is also what collapses every
 * withdrawn member into one row, since UNION deduplicates NULL with NULL.
 *
 * What each row leads with is ConversationScope's own two arms, correlated to the row's counterpart
 * instead of a bound member — a shape the scope's Eloquent builder cannot be reused in. The list and
 * the per-conversation reads are therefore pinned against each other by test rather than by sharing
 * code (tests/Feature/DirectMessage/Conversation/ConversationListBeltTest.php).
 */
class ConversationList
{
    /** Conversations per page (OpenPNE 3 app_message_pagenatesize, the mailbox's own size). */
    public const PER_PAGE = 20;

    /** @return LengthAwarePaginator<int, ConversationSummary> */
    public function __invoke(Member $viewer, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $viewerId = (int) $viewer->getKey();

        $page = DB::query()
            ->fromSub($this->counterparts($viewerId), 'heads')
            ->select('heads.counterpart_id')
            ->selectSub($this->newest($viewerId, 'created_at'), 'latest_at')
            ->selectSub($this->newest($viewerId, 'id'), 'latest_id')
            ->selectSub($this->unread($viewerId), 'unread_count')
            // No "nothing said yet" case for the order to place: a counterpart is here because a
            // message is. The counterpart itself is the final tie-break — an upgraded
            // multi-recipient send is the shared latest of every conversation it landed in, so
            // (latest_at, latest_id) alone is not a total order, and an offset page boundary would
            // duplicate or drop a row on whatever order the engine felt like. The withdrawn bucket
            // sorts last within such a tie, spelled as a CASE because where an engine collates NULL
            // in a descending sort is not a portable answer.
            ->orderByDesc('latest_at')
            ->orderByDesc('latest_id')
            ->orderByRaw('case when heads.counterpart_id is null then 1 else 0 end')
            ->orderByDesc('heads.counterpart_id')
            ->paginate($perPage);

        return $page->setCollection($this->summaries($page->getCollection()));
    }

    /**
     * Everyone the viewer is corresponding with: the recipients of what they sent and still hold,
     * and the senders of what they received and still hold. Per-side visibility and nothing else,
     * so a conversation the viewer has emptied from their side leaves the list while staying whole
     * in the other's.
     */
    private function counterparts(int $viewerId): Builder
    {
        $sent = DB::table('direct_message_recipients as delivery')
            ->join('direct_messages as message', 'message.id', '=', 'delivery.direct_message_id')
            ->where('message.sender_id', $viewerId)
            ->where('message.is_draft', false)
            ->whereNull('message.sender_deleted_at')
            ->whereNull('message.sender_purged_at')
            ->select('delivery.recipient_id as counterpart_id');

        $received = DB::table('direct_message_recipients as delivery')
            ->join('direct_messages as message', 'message.id', '=', 'delivery.direct_message_id')
            ->where('delivery.recipient_id', $viewerId)
            ->whereNull('delivery.recipient_deleted_at')
            ->whereNull('delivery.recipient_purged_at')
            ->where('message.is_draft', false)
            ->select('message.sender_id as counterpart_id');

        return $sent->union($received);
    }

    /** One column of the conversation's newest message, by the `(created_at, id)` tuple. */
    private function newest(int $viewerId, string $column): Builder
    {
        return $this->conversation($viewerId)
            ->select("conversation.{$column}")
            ->orderByDesc('conversation.created_at')
            ->orderByDesc('conversation.id')
            ->limit(1);
    }

    /** How many messages of this conversation are waiting: the received arm with an unopened receipt. */
    private function unread(int $viewerId): Builder
    {
        $query = DB::table('direct_messages as conversation')
            ->selectRaw('count(*)')
            ->where('conversation.is_draft', false)
            ->whereExists(fn (Builder $receipt) => $this->viewerReceipt($receipt, $viewerId)->whereNull('delivery.read_at'));

        return $this->isCounterpart($query, 'conversation.sender_id');
    }

    /** ConversationScope's two arms, correlated to the row's counterpart rather than a bound member. */
    private function conversation(int $viewerId): Builder
    {
        return DB::table('direct_messages as conversation')
            ->where('conversation.is_draft', false)
            ->where(fn (Builder $arms) => $arms
                ->where(fn (Builder $sent) => $sent
                    ->where('conversation.sender_id', $viewerId)
                    ->whereNull('conversation.sender_deleted_at')
                    ->whereNull('conversation.sender_purged_at')
                    // The counterpart is named on the receipt here, so the comparison goes inside
                    // the EXISTS: `delivery` does not exist in the arm's own scope.
                    ->whereExists(fn (Builder $receipt) => $this->isCounterpart(
                        $receipt
                            ->select(DB::raw('1'))
                            ->from('direct_message_recipients as delivery')
                            ->whereColumn('delivery.direct_message_id', 'conversation.id'),
                        'delivery.recipient_id',
                    )))
                ->orWhere(fn (Builder $received) => $this->isCounterpart($received, 'conversation.sender_id')
                    ->whereExists(fn (Builder $receipt) => $this->viewerReceipt($receipt, $viewerId))));
    }

    /** The viewer's own live receipt of the row — what makes a received message theirs to read. */
    private function viewerReceipt(Builder $query, int $viewerId): Builder
    {
        return $query
            ->select(DB::raw('1'))
            ->from('direct_message_recipients as delivery')
            ->whereColumn('delivery.direct_message_id', 'conversation.id')
            ->where('delivery.recipient_id', $viewerId)
            ->whereNull('delivery.recipient_deleted_at')
            ->whereNull('delivery.recipient_purged_at');
    }

    /**
     * `$column` names the row's counterpart, written out as `= it OR (both IS NULL)`: binding a null
     * would make every comparison UNKNOWN and lose the withdrawn bucket, and MySQL's null-safe `<=>`
     * is not SQL SQLite speaks.
     */
    private function isCounterpart(Builder $query, string $column): Builder
    {
        return $query->where(fn (Builder $match) => $match
            ->whereColumn($column, 'heads.counterpart_id')
            ->orWhere(fn (Builder $withdrawn) => $withdrawn->whereNull($column)->whereNull('heads.counterpart_id')));
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return Collection<int, ConversationSummary>
     */
    private function summaries(Collection $rows): Collection
    {
        // The ordering already named the exact rows, so both of these are lookups by key rather than
        // a "newest message" query per conversation.
        $memberIds = $rows->pluck('counterpart_id')->filter()->map(static fn ($id): int => (int) $id)->all();
        $members = $memberIds === []
            ? new Collection
            : Member::query()->whereIn('id', $memberIds)->with('avatar.file')->get()->keyBy('id');

        $messageIds = $rows->pluck('latest_id')->map(static fn ($id): int => (int) $id)->all();
        $messages = $messageIds === []
            ? new Collection
            : DirectMessage::query()->whereIn('id', $messageIds)->get()->keyBy('id');

        return $rows->map(fn (object $row): ConversationSummary => new ConversationSummary(
            counterpart: $row->counterpart_id === null ? null : $members[(int) $row->counterpart_id],
            latest: $messages[(int) $row->latest_id],
            unread: (int) $row->unread_count,
        ));
    }
}
