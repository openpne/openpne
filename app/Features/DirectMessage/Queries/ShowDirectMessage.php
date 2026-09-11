<?php

namespace App\Features\DirectMessage\Queries;

use App\Features\DirectMessage\DirectMessageBox;
use App\Features\DirectMessage\DirectMessageNotificationRows;
use App\Features\DirectMessage\DirectMessageView;
use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Null when the viewer may not read the message in that box. Opening a received one marks it read,
 * the side effect OpenPNE 3's isReadable had.
 */
class ShowDirectMessage
{
    public function __construct(private readonly DirectMessageNotificationRows $feedRows) {}

    public function __invoke(Member $viewer, DirectMessageBox $box, int $messageId): ?DirectMessageView
    {
        $message = DirectMessage::query()
            ->with(['sender.avatar.file', 'recipients.recipient.avatar.file', 'draftRecipient.avatar.file', 'files.file'])
            ->find($messageId);
        $position = $message === null ? null : $this->position($viewer, $box, $messageId);
        if ($message === null || $position === null) {
            return null;
        }

        if ($box === DirectMessageBox::Receive) {
            $this->markRead($viewer, $message);
        }

        $viewerIsSender = (int) $message->sender_id === (int) $viewer->getKey();

        return new DirectMessageView(
            $message,
            $box,
            $viewerIsSender,
            $this->counterparties($message, $viewerIsSender),
            $this->adjacentId($viewer, $box, $position, older: true),
            $this->adjacentId($viewer, $box, $position, older: false),
        );
    }

    /** The message's place in the box, or null when the viewer may not read it there. */
    private function position(Member $viewer, DirectMessageBox $box, int $messageId): ?object
    {
        return DB::query()
            ->fromSub($this->onePlacePerMessage($viewer, $box), 'box')
            ->where('id', $messageId)
            ->first();
    }

    private function markRead(Member $viewer, DirectMessage $message): void
    {
        $receipt = $message->recipients->first(fn (DirectMessageRecipient $r): bool => (int) $r->recipient_id === (int) $viewer->getKey()
            && $r->recipient_deleted_at === null
            && $r->recipient_purged_at === null);

        if ($receipt !== null && $receipt->read_at === null) {
            $receipt->forceFill(['read_at' => now()])->save();
        }

        $this->feedRows->markReadFor($viewer);
    }

    /**
     * OpenPNE 3 fromOrToMembers: the To set when the viewer is the sender, the single From member
     * otherwise.
     *
     * @return list<Member>
     */
    private function counterparties(DirectMessage $message, bool $viewerIsSender): array
    {
        if (! $viewerIsSender) {
            return array_values(array_filter([$message->sender]));
        }

        return $message->is_draft
            ? array_values(array_filter([$message->draftRecipient]))
            : $message->recipients->map(fn (DirectMessageRecipient $r) => $r->recipient)->filter()->values()->all();
    }

    private function adjacentId(Member $viewer, DirectMessageBox $box, object $position, bool $older): ?int
    {
        // A row with no time has no place in the order, so it has no neighbours.
        if ($position->sort_at === null) {
            return null;
        }

        $op = $older ? '<' : '>';
        $direction = $older ? 'desc' : 'asc';

        // The box list orders by (sort_at, role, row_id); the neighbour is the next message in that order.
        $row = DB::query()
            ->fromSub($this->onePlacePerMessage($viewer, $box), 'box')
            ->where(fn (QueryBuilder $q) => $q
                ->where('sort_at', $op, $position->sort_at)
                ->orWhere(fn (QueryBuilder $tie) => $tie
                    ->where('sort_at', '=', $position->sort_at)
                    ->where(fn (QueryBuilder $r) => $r
                        ->where('role', $op, $position->role)
                        ->orWhere(fn (QueryBuilder $rr) => $rr
                            ->where('role', '=', $position->role)
                            ->where('row_id', $op, $position->row_id)))))
            ->orderBy('sort_at', $direction)->orderBy('role', $direction)->orderBy('row_id', $direction)
            ->first();

        return $row !== null ? (int) $row->id : null;
    }

    /**
     * A message with two rows in one box (a duplicate receipt, which OpenPNE 3 data may carry) keeps its
     * later row only, so the walk visits each message once and never turns back on itself. The window
     * ranks a row with a NULL time last on MySQL 8 and SQLite alike, so the timed row is the one kept.
     */
    private function onePlacePerMessage(Member $viewer, DirectMessageBox $box): QueryBuilder
    {
        $ranked = DB::query()
            ->fromSub($this->boxRows($viewer, $box), 'b')
            ->selectRaw('b.*, row_number() over (partition by b.id order by b.sort_at desc, b.role desc, b.row_id desc) as place');

        return DB::query()->fromSub($ranked, 'ranked')->where('place', 1);
    }

    /**
     * Every arm yields (id = message id, sort_at, role, row_id): the box list's order columns, so the
     * show page walks the list's order (docs/internals/direct-messages.md, "Ordering and paging").
     */
    private function boxRows(Member $viewer, DirectMessageBox $box): QueryBuilder
    {
        $id = $viewer->getKey();

        return match ($box) {
            DirectMessageBox::Receive => DirectMessageRecipient::query()->ofDelivered()->recipientLive()
                ->where('recipient_id', $id)
                ->select('direct_message_id as id', 'created_at as sort_at', DB::raw("'received' as role"), 'id as row_id')->toBase(),
            DirectMessageBox::Sent => DirectMessage::query()->senderLive()
                ->where('sender_id', $id)->where('is_draft', false)
                ->select('id', 'created_at as sort_at', DB::raw("'sent' as role"), 'id as row_id')->toBase(),
            DirectMessageBox::Trash => DirectMessageRecipient::query()->ofDelivered()->recipientTrashed()
                ->where('recipient_id', $id)
                ->select('direct_message_id as id', 'recipient_deleted_at as sort_at', DB::raw("'received' as role"), 'id as row_id')->toBase()
                ->unionAll(
                    DirectMessage::query()->senderTrashed()->where('sender_id', $id)
                        ->select('id', 'sender_deleted_at as sort_at', DB::raw("'sent' as role"), 'id as row_id')->toBase()
                ),
            // A draft has no show page, so no id is in this box.
            DirectMessageBox::Draft => DirectMessage::query()->whereRaw('1 = 0')
                ->select('id', 'created_at as sort_at', DB::raw("'sent' as role"), 'id as row_id')->toBase(),
        };
    }
}
