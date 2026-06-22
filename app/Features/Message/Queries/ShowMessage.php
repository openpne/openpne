<?php

namespace App\Features\Message\Queries;

use App\Features\Message\MessageBox;
use App\Features\Message\MessageView;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Resolve one message for a box's show page (OpenPNE 3 message/show), or null when the viewer may
 * not read it in that box. Opening a received message marks it read (OpenPNE 3 isReadable side
 * effect). Also resolves the previous/next message within the box for the show-page pager.
 */
class ShowMessage
{
    public function __invoke(Member $viewer, MessageBox $box, int $messageId): ?MessageView
    {
        $message = Message::query()->with(['sender', 'recipients.recipient', 'files.file'])->find($messageId);
        if ($message === null) {
            return null;
        }

        $viewerIsSender = (int) $message->sender_id === (int) $viewer->getKey();

        $inBox = match ($box) {
            MessageBox::Receive => $this->resolveReceived($viewer, $message),
            MessageBox::Sent => $viewerIsSender && ! $message->is_draft
                && $message->sender_deleted_at === null && $message->sender_purged_at === null,
            MessageBox::Trash => $this->isInTrash($viewer, $message, $viewerIsSender),
            MessageBox::Draft => false, // drafts have no show page (opened via the edit form, write surface)
        };

        if (! $inBox) {
            return null;
        }

        $counterparties = $viewerIsSender
            ? $message->recipients->map(fn (MessageRecipient $r) => $r->recipient)->filter()->values()->all()
            : array_values(array_filter([$message->sender]));

        return new MessageView(
            $message,
            $box,
            $viewerIsSender,
            $counterparties,
            $this->adjacentId($viewer, $box, $messageId, older: true),
            $this->adjacentId($viewer, $box, $messageId, older: false),
        );
    }

    /** Receive box: the viewer has a live (non-trashed) receipt. Marks it read on open. */
    private function resolveReceived(Member $viewer, Message $message): bool
    {
        if ($message->is_draft) {
            return false;
        }

        $receipt = $message->recipients
            ->first(fn (MessageRecipient $r): bool => (int) $r->recipient_id === (int) $viewer->getKey()
                && $r->recipient_deleted_at === null
                && $r->recipient_purged_at === null);

        if ($receipt === null) {
            return false;
        }

        if ($receipt->read_at === null) {
            $receipt->forceFill(['read_at' => now()])->save();
        }

        return true;
    }

    /** Trash box: the viewer trashed (not yet purged) this message on either side. */
    private function isInTrash(Member $viewer, Message $message, bool $viewerIsSender): bool
    {
        if ($viewerIsSender) {
            return $message->sender_deleted_at !== null && $message->sender_purged_at === null;
        }

        // A draft is never the recipient's, even via trash — only the sender works a draft.
        if ($message->is_draft) {
            return false;
        }

        return $message->recipients
            ->contains(fn (MessageRecipient $r): bool => (int) $r->recipient_id === (int) $viewer->getKey()
                && $r->recipient_deleted_at !== null
                && $r->recipient_purged_at === null);
    }

    /** The adjacent message id within the box: the nearest older (id <) or newer (id >) one. */
    private function adjacentId(Member $viewer, MessageBox $box, int $messageId, bool $older): ?int
    {
        // Wrap in a subquery so the id filter applies to the whole box set, including the trash UNION.
        $row = DB::query()
            ->fromSub($this->boxMessageIds($viewer, $box), 'box')
            ->where('id', $older ? '<' : '>', $messageId)
            ->orderBy('id', $older ? 'desc' : 'asc')
            ->first();

        return $row !== null ? (int) $row->id : null;
    }

    /** Message ids in the box for this viewer (used for adjacency). @return QueryBuilder */
    private function boxMessageIds(Member $viewer, MessageBox $box): QueryBuilder
    {
        $id = $viewer->getKey();

        return match ($box) {
            MessageBox::Receive => DB::table('message_recipients')
                ->join('messages', 'messages.id', '=', 'message_recipients.message_id')
                ->where('message_recipients.recipient_id', $id)
                ->whereNull('message_recipients.recipient_deleted_at')
                ->whereNull('message_recipients.recipient_purged_at')
                ->where('messages.is_draft', false)
                ->select('messages.id as id'),
            MessageBox::Sent => DB::table('messages')
                ->where('sender_id', $id)
                ->where('is_draft', false)
                ->whereNull('sender_deleted_at')
                ->whereNull('sender_purged_at')
                ->select('id'),
            MessageBox::Trash => DB::table('message_recipients')
                ->join('messages', 'messages.id', '=', 'message_recipients.message_id')
                ->where('message_recipients.recipient_id', $id)
                ->where('messages.is_draft', false) // a draft is never the recipient's, even in trash
                ->whereNotNull('message_recipients.recipient_deleted_at')
                ->whereNull('message_recipients.recipient_purged_at')
                ->select('messages.id as id')
                ->unionAll(
                    DB::table('messages')
                        ->where('sender_id', $id)
                        ->whereNotNull('sender_deleted_at')
                        ->whereNull('sender_purged_at')
                        ->select('id')
                ),
            MessageBox::Draft => DB::table('messages')->whereRaw('1 = 0')->select('id'),
        };
    }
}
