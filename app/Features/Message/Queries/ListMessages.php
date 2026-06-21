<?php

namespace App\Features\Message\Queries;

use App\Features\Message\MessageBox;
use App\Features\Message\MessageListItem;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One member's view of a message box (OpenPNE 3 message/list), newest first, as normalized
 * MessageListItems. Each box draws from the side that owns its state: the inbox and the
 * recipient half of trash from message_recipients; sent/draft and the sender half of trash from
 * messages. A purged or (per side) trashed row never appears in an active box.
 */
class ListMessages
{
    /** OpenPNE 3 app_message_pagenatesize default. */
    public const PER_PAGE = 20;

    /** @return LengthAwarePaginator<int, MessageListItem> */
    public function __invoke(Member $viewer, MessageBox $box, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return match ($box) {
            MessageBox::Receive => $this->received($viewer, $perPage),
            MessageBox::Sent => $this->sent($viewer, false, $perPage),
            MessageBox::Draft => $this->sent($viewer, true, $perPage),
            MessageBox::Trash => $this->trash($viewer, $perPage),
        };
    }

    /** @return LengthAwarePaginator<int, MessageListItem> */
    private function received(Member $viewer, int $perPage): LengthAwarePaginator
    {
        return MessageRecipient::query()
            ->join('messages', 'messages.id', '=', 'message_recipients.message_id')
            ->where('message_recipients.recipient_id', $viewer->getKey())
            ->whereNull('message_recipients.recipient_deleted_at')
            ->whereNull('message_recipients.recipient_purged_at')
            ->where('messages.is_draft', false)
            ->with('message.sender')
            // OpenPNE 3 sorts/dates the inbox by the receipt (MessageSendList.created_at), not the
            // message — a draft delivered later appears by its delivery time, not its authoring time.
            ->orderByDesc('message_recipients.created_at')
            ->select('message_recipients.*')
            ->paginate($perPage)
            ->through(fn (MessageRecipient $r): MessageListItem => new MessageListItem(
                (int) $r->message_id,
                $r->message?->sender,
                (string) $r->message?->subject,
                $r->created_at,
                $r->read_at === null,
            ));
    }

    /** Sent box (draft=false) or draft box (draft=true): messages this member authored. @return LengthAwarePaginator<int, MessageListItem> */
    private function sent(Member $viewer, bool $draft, int $perPage): LengthAwarePaginator
    {
        return Message::query()
            ->where('sender_id', $viewer->getKey())
            ->where('is_draft', $draft)
            ->whereNull('sender_deleted_at')
            ->whereNull('sender_purged_at')
            ->with('recipients.recipient')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->through(fn (Message $m): MessageListItem => new MessageListItem(
                (int) $m->getKey(),
                $m->recipients->first()?->recipient,
                (string) $m->subject,
                $m->created_at,
                false,
            ));
    }

    /**
     * Trash mixes both sides: messages this member trashed as sender and receipts trashed as
     * recipient, newest first. Paginated through a UNION of the two id sets, then hydrated.
     *
     * @return LengthAwarePaginator<int, MessageListItem>
     */
    private function trash(Member $viewer, int $perPage): LengthAwarePaginator
    {
        $id = $viewer->getKey();

        // OpenPNE 3 sorts/dates the trash by the moved-to-trash time (DeletedMessage.created_at),
        // which folds onto the per-side *_deleted_at column here.
        $received = DB::table('message_recipients')
            ->join('messages', 'messages.id', '=', 'message_recipients.message_id')
            ->where('message_recipients.recipient_id', $id)
            ->whereNotNull('message_recipients.recipient_deleted_at')
            ->whereNull('message_recipients.recipient_purged_at')
            ->select('messages.id as message_id', 'message_recipients.recipient_deleted_at as sort_at', DB::raw("'received' as role"));

        $sent = DB::table('messages')
            ->where('messages.sender_id', $id)
            ->whereNotNull('messages.sender_deleted_at')
            ->whereNull('messages.sender_purged_at')
            ->select('messages.id as message_id', 'messages.sender_deleted_at as sort_at', DB::raw("'sent' as role"));

        $page = $received->unionAll($sent)->orderByDesc('sort_at')->paginate($perPage);

        /** @var array<int, \stdClass> $rows */
        $rows = $page->items();
        $messages = Message::query()
            ->with(['sender', 'recipients.recipient'])
            ->whereIn('id', array_map(static fn ($r): int => (int) $r->message_id, $rows))
            ->get()
            ->keyBy('id');

        return $page->through(function (object $row) use ($messages): MessageListItem {
            $message = $messages[$row->message_id];
            $counterparty = $row->role === 'received'
                ? $message->sender
                : $message->recipients->first()?->recipient;

            return new MessageListItem(
                (int) $message->getKey(),
                $counterparty,
                (string) $message->subject,
                Carbon::parse($row->sort_at),
                false,
            );
        });
    }
}
