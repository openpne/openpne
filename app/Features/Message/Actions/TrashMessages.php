<?php

namespace App\Features\Message\Actions;

use App\Features\Message\MessageBox;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;

/**
 * Move the viewer's side of each message in a box to the trash (OpenPNE 3 message/delete on the
 * receive/send/draft boxes). The box fixes the side: the inbox trashes the receipt, the sent and
 * draft boxes trash the authored message. Set-based so one call covers a single message or a bulk
 * selection, and idempotent — an already-trashed row keeps its first moved-to-trash time, the column
 * the trash box sorts on.
 */
class TrashMessages
{
    /**
     * @param  array<int, int|string>  $messageIds
     * @return int rows moved to trash
     */
    public function __invoke(Member $viewer, MessageBox $box, array $messageIds): int
    {
        $ids = array_values(array_unique(array_map('intval', $messageIds)));
        if ($ids === []) {
            return 0;
        }

        $viewerId = (int) $viewer->getKey();

        return match ($box) {
            MessageBox::Receive => MessageRecipient::query()
                ->where('recipient_id', $viewerId)
                ->whereIn('message_id', $ids)
                ->whereNull('recipient_deleted_at')
                ->whereNull('recipient_purged_at')
                ->update(['recipient_deleted_at' => now()]),
            MessageBox::Sent, MessageBox::Draft => Message::query()
                ->where('sender_id', $viewerId)
                ->whereIn('id', $ids)
                ->whereNull('sender_deleted_at')
                ->whereNull('sender_purged_at')
                ->update(['sender_deleted_at' => now()]),
            MessageBox::Trash => 0, // the trash box restores or purges, it does not trash
        };
    }
}
