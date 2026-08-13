<?php

namespace App\Features\DirectMessage\Actions;

use App\Features\DirectMessage\DirectMessageBox;
use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Member;

/**
 * Move the viewer's side of each message in a box to the trash. The box fixes the side: the
 * inbox trashes the receipt, the sent and
 * draft boxes trash the authored message. Set-based so one call covers a single message or a bulk
 * selection, and idempotent — an already-trashed row keeps its first moved-to-trash time, the column
 * the trash box sorts on.
 */
class TrashDirectMessages
{
    /**
     * @param  array<int, int|string>  $messageIds
     * @return int rows moved to trash
     */
    public function __invoke(Member $viewer, DirectMessageBox $box, array $messageIds): int
    {
        $ids = array_values(array_unique(array_map('intval', $messageIds)));
        if ($ids === []) {
            return 0;
        }

        $viewerId = (int) $viewer->getKey();

        return match ($box) {
            DirectMessageBox::Receive => DirectMessageRecipient::query()
                ->ofDelivered() // a draft is never the recipient's, even to trash
                ->recipientLive()
                ->where('recipient_id', $viewerId)
                ->whereIn('direct_message_id', $ids)
                ->update(['recipient_deleted_at' => now()]),
            // The sender owns both their sent box and their drafts; each box trashes only its own kind,
            // so a sent id submitted as a draft (or vice versa) cannot cross boxes.
            DirectMessageBox::Sent, DirectMessageBox::Draft => DirectMessage::query()
                ->senderLive()
                ->where('sender_id', $viewerId)
                ->whereIn('id', $ids)
                ->where('is_draft', $box === DirectMessageBox::Draft)
                ->update(['sender_deleted_at' => now()]),
            DirectMessageBox::Trash => 0, // the trash box restores or purges, it does not trash
        };
    }
}
