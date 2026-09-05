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
        if ($message === null || ! $this->inBox($viewer, $box, $messageId)) {
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
            $this->adjacentId($viewer, $box, $messageId, older: true),
            $this->adjacentId($viewer, $box, $messageId, older: false),
        );
    }

    private function inBox(Member $viewer, DirectMessageBox $box, int $messageId): bool
    {
        return DB::query()->fromSub($this->boxMessageIds($viewer, $box), 'box')->where('id', $messageId)->exists();
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

    private function adjacentId(Member $viewer, DirectMessageBox $box, int $messageId, bool $older): ?int
    {
        // Wrap in a subquery so the id filter applies to the whole box set, including the trash UNION.
        $row = DB::query()
            ->fromSub($this->boxMessageIds($viewer, $box), 'box')
            ->where('id', $older ? '<' : '>', $messageId)
            ->orderBy('id', $older ? 'desc' : 'asc')
            ->first();

        return $row !== null ? (int) $row->id : null;
    }

    private function boxMessageIds(Member $viewer, DirectMessageBox $box): QueryBuilder
    {
        $id = $viewer->getKey();

        return match ($box) {
            DirectMessageBox::Receive => DirectMessageRecipient::query()->ofDelivered()->recipientLive()
                ->where('recipient_id', $id)->select('direct_message_id as id')->toBase(),
            DirectMessageBox::Sent => DirectMessage::query()->senderLive()
                ->where('sender_id', $id)->where('is_draft', false)->select('id')->toBase(),
            DirectMessageBox::Trash => DirectMessageRecipient::query()->ofDelivered()->recipientTrashed()
                ->where('recipient_id', $id)->select('direct_message_id as id')->toBase()
                ->unionAll(
                    DirectMessage::query()->senderTrashed()->where('sender_id', $id)->select('id')->toBase()
                ),
            // A draft has no show page, so no id is in this box.
            DirectMessageBox::Draft => DirectMessage::query()->whereRaw('1 = 0')->select('id')->toBase(),
        };
    }
}
