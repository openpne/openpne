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
 * The box conditions live in the model scopes rather than here, so every reader of a box states them
 * once.
 */
class ListDirectMessages
{
    /** OpenPNE 3 app_message_pagenatesize default. */
    public const PER_PAGE = 20;

    /**
     * `$withRepliedStatus` costs one extra query per page, so it is opt-in. `$pageName` moves the
     * page parameter, so a screen showing this box beside another paged list pages them separately.
     *
     * @return LengthAwarePaginator<int, DirectMessageListItem>
     */
    public function __invoke(Member $viewer, DirectMessageBox $box, int $perPage = self::PER_PAGE, bool $withRepliedStatus = false, string $pageName = 'page'): LengthAwarePaginator
    {
        return match ($box) {
            DirectMessageBox::Receive => $this->received($viewer, $perPage, $withRepliedStatus, $pageName),
            DirectMessageBox::Sent => $this->authored($viewer, false, $perPage, $pageName),
            DirectMessageBox::Draft => $this->authored($viewer, true, $perPage, $pageName),
            DirectMessageBox::Trash => $this->trash($viewer, $perPage, $pageName),
        };
    }

    /** @return LengthAwarePaginator<int, DirectMessageListItem> */
    private function received(Member $viewer, int $perPage, bool $withRepliedStatus, string $pageName): LengthAwarePaginator
    {
        $page = DirectMessageRecipient::query()
            ->ofDelivered()
            ->recipientLive()
            ->where('recipient_id', $viewer->getKey())
            ->with('directMessage.sender.avatar.file')
            // OpenPNE 3 dates the inbox by the receipt (MessageSendList.created_at), not the message.
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], $pageName);

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
     * OpenPNE 3 is_hensin: a sent message of the viewer's whose return_message_id points back, folded
     * onto `parent_id` here. Trash state does not clear it, as OpenPNE 3 keeps the sender's row when
     * the reply is trashed.
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
     * A draft has no receipt, so its recipient is read from the `draft_recipient_id` column.
     *
     * @return LengthAwarePaginator<int, DirectMessageListItem>
     */
    private function authored(Member $viewer, bool $draft, int $perPage, string $pageName): LengthAwarePaginator
    {
        return DirectMessage::query()
            ->where('sender_id', $viewer->getKey())
            ->where('is_draft', $draft)
            ->senderLive()
            ->with($draft ? 'draftRecipient.avatar.file' : 'recipients.recipient.avatar.file')
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], $pageName)
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
     * The trash box mixes both sides, so it pages a UNION of the two id sets rather than one table's
     * rows.
     *
     * @return LengthAwarePaginator<int, DirectMessageListItem>
     */
    private function trash(Member $viewer, int $perPage, string $pageName): LengthAwarePaginator
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

        $page = $received->unionAll($sent)->orderByDesc('sort_at')->paginate($perPage, ['*'], $pageName);

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
