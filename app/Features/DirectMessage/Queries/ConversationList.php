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
 * The order is decided in SQL, before the page is cut, and each row's lead message restates
 * `ConversationScope`'s two arms as a correlated subquery the scope's builder cannot be reused in
 * (`docs/internals/direct-messages.md`, "The conversation list").
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
     * Per-side visibility and nothing else, so a conversation the viewer has emptied from their side
     * leaves the list while staying whole in the other's.
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

    private function newest(int $viewerId, string $column): Builder
    {
        return $this->conversation($viewerId)
            ->select("conversation.{$column}")
            ->orderByDesc('conversation.created_at')
            ->orderByDesc('conversation.id')
            ->limit(1);
    }

    private function unread(int $viewerId): Builder
    {
        $query = DB::table('direct_messages as conversation')
            ->selectRaw('count(*)')
            ->where('conversation.is_draft', false)
            ->whereExists(fn (Builder $receipt) => $this->viewerReceipt($receipt, $viewerId)->whereNull('delivery.read_at'));

        return $this->isCounterpart($query, 'conversation.sender_id');
    }

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
