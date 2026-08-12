<?php

namespace App\Features\DirectMessage\Queries;

use App\Features\DirectMessage\DirectMessageBox;
use App\Features\DirectMessage\DirectMessageListItem;
use App\Features\DirectMessage\DirectMessageRowStatus;
use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One member's view of a message box, newest first, as normalized
 * DirectMessageListItems. Each box draws from the side that owns its state: the inbox and the recipient
 * half of trash from direct_message_recipients; sent/draft and the sender half of trash from direct_messages. The
 * box conditions live in model scopes (DirectMessage::senderLive/Trashed, DirectMessageRecipient::ofDelivered/
 * recipientLive/Trashed) so every query here, ShowDirectMessage, and the trash actions agree on them.
 */
class ListDirectMessages
{
    /** OpenPNE 3 app_message_pagenatesize default. */
    public const PER_PAGE = 20;

    /**
     * $withRepliedStatus opts into the inbox replied lookup (one extra query) — only the Classic
     * status icons read it, so only that surface pays for it.
     *
     * @return LengthAwarePaginator<int, DirectMessageListItem>
     */
    public function __invoke(Member $viewer, DirectMessageBox $box, int $perPage = self::PER_PAGE, bool $withRepliedStatus = false): LengthAwarePaginator
    {
        return match ($box) {
            DirectMessageBox::Receive => $this->received($viewer, $perPage, $withRepliedStatus),
            DirectMessageBox::Sent => $this->authored($viewer, false, $perPage),
            DirectMessageBox::Draft => $this->authored($viewer, true, $perPage),
            DirectMessageBox::Trash => $this->trash($viewer, $perPage),
        };
    }

    /** @return LengthAwarePaginator<int, DirectMessageListItem> */
    private function received(Member $viewer, int $perPage, bool $withRepliedStatus): LengthAwarePaginator
    {
        $page = DirectMessageRecipient::query()
            ->ofDelivered()
            ->recipientLive()
            ->where('recipient_id', $viewer->getKey())
            ->with('directMessage.sender.avatar.file')
            // OpenPNE 3 dates the inbox by the receipt (MessageSendList.created_at), not the message,
            // so a message delivered later sorts by its delivery time, not its authoring time.
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $replied = $withRepliedStatus ? $this->repliedTo($viewer, $page->getCollection()->map(
            static fn (DirectMessageRecipient $r): int => (int) $r->direct_message_id
        )->all()) : [];

        return $page->through(fn (DirectMessageRecipient $r): DirectMessageListItem => new DirectMessageListItem(
            (int) $r->direct_message_id,
            $r->directMessage?->sender,
            (string) $r->directMessage?->subject,
            $r->created_at,
            $r->read_at === null,
            match (true) {
                isset($replied[(int) $r->direct_message_id]) => DirectMessageRowStatus::Replied,
                $r->read_at === null => DirectMessageRowStatus::Unopened,
                default => DirectMessageRowStatus::Opened,
            },
        ));
    }

    /**
     * Which of these messages the viewer has replied to (OpenPNE 3 is_hensin: a sent message of
     * theirs whose return_message_id points back, folded onto parent_id here). Trash state does not
     * clear it — OpenPNE 3 keeps the sender's row when the reply is trashed. One query per page.
     *
     * @param  list<int>  $messageIds
     * @return array<int, true>
     */
    private function repliedTo(Member $viewer, array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        $parentIds = DirectMessage::query()
            ->where('sender_id', $viewer->getKey())
            ->where('is_draft', false)
            ->whereIn('parent_id', $messageIds)
            ->pluck('parent_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return array_fill_keys($parentIds, true);
    }

    /**
     * Sent box (draft=false) or draft box (draft=true): messages this member authored. A draft has no
     * receipt, so its recipient is read from the draft_recipient_id column.
     *
     * @return LengthAwarePaginator<int, DirectMessageListItem>
     */
    private function authored(Member $viewer, bool $draft, int $perPage): LengthAwarePaginator
    {
        return DirectMessage::query()
            ->where('sender_id', $viewer->getKey())
            ->where('is_draft', $draft)
            ->senderLive()
            ->with($draft ? 'draftRecipient.avatar.file' : 'recipients.recipient.avatar.file')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->through(fn (DirectMessage $m): DirectMessageListItem => new DirectMessageListItem(
                (int) $m->getKey(),
                $draft ? $m->draftRecipient : $m->recipients->first()?->recipient,
                (string) $m->subject,
                $m->created_at,
                false,
                $draft ? DirectMessageRowStatus::Draft : DirectMessageRowStatus::Sent,
            ));
    }

    /**
     * Trash mixes both sides: messages this member trashed as sender and receipts trashed as
     * recipient, newest first. Paginated through a UNION of the two id sets, then hydrated.
     *
     * @return LengthAwarePaginator<int, DirectMessageListItem>
     */
    private function trash(Member $viewer, int $perPage): LengthAwarePaginator
    {
        $id = $viewer->getKey();

        // OpenPNE 3 dates the trash by the moved-to-trash time (DeletedMessage.created_at), which
        // folds onto the per-side *_deleted_at column here.
        $received = DirectMessageRecipient::query()
            ->ofDelivered()
            ->recipientTrashed()
            ->where('recipient_id', $id)
            ->select('direct_message_id', 'recipient_deleted_at as sort_at', DB::raw("'received' as role"))
            ->toBase();

        $sent = DirectMessage::query()
            ->senderTrashed()
            ->where('sender_id', $id)
            ->select('id as direct_message_id', 'sender_deleted_at as sort_at', DB::raw("'sent' as role"))
            ->toBase();

        $page = $received->unionAll($sent)->orderByDesc('sort_at')->paginate($perPage);

        /** @var array<int, \stdClass> $rows */
        $rows = $page->items();
        $messages = DirectMessage::query()
            ->with(['sender.avatar.file', 'recipients.recipient.avatar.file', 'draftRecipient.avatar.file'])
            ->whereIn('id', array_map(static fn ($r): int => (int) $r->direct_message_id, $rows))
            ->get()
            ->keyBy('id');

        return $page->through(function (object $row) use ($messages): DirectMessageListItem {
            $message = $messages[$row->direct_message_id];
            $counterparty = match ($row->role) {
                'received' => $message->sender,
                // A trashed sender-side row is a sent message (recipient on its receipt) or a draft
                // (recipient on the column).
                default => $message->is_draft ? $message->draftRecipient : $message->recipients->first()?->recipient,
            };

            return new DirectMessageListItem(
                (int) $message->getKey(),
                $counterparty,
                (string) $message->subject,
                Carbon::parse($row->sort_at),
                false,
                // OpenPNE 3 labels a trashed row by the box it came from, not by a read state.
                match (true) {
                    $row->role === 'received' => DirectMessageRowStatus::Received,
                    $message->is_draft => DirectMessageRowStatus::Draft,
                    default => DirectMessageRowStatus::Sent,
                },
            );
        });
    }
}
