<?php

namespace App\Features\Message\Actions;

use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use Illuminate\Support\Facades\DB;

/**
 * Purge the viewer's side of each trashed message (OpenPNE 3 message/delete on the dust box). Purge
 * only ever follows trash, so it sets *_purged_at where *_deleted_at is already set — keeping the
 * purge⇒deleted invariant the boxes rely on. The row and any attached file bytes stay: the other
 * side may still hold the message, and a purged side simply loses its view (OpenPNE 3 left the bytes
 * too). Only the per-side timestamps move; nothing is physically deleted.
 */
class PurgeMessages
{
    /**
     * @param  array<int, int|string>  $messageIds
     * @return int rows purged
     */
    public function __invoke(Member $viewer, array $messageIds): int
    {
        $ids = array_values(array_unique(array_map('intval', $messageIds)));
        if ($ids === []) {
            return 0;
        }

        $viewerId = (int) $viewer->getKey();

        return DB::transaction(function () use ($ids, $viewerId): int {
            $sender = Message::query()
                ->senderTrashed()
                ->where('sender_id', $viewerId)
                ->whereIn('id', $ids)
                ->update(['sender_purged_at' => now()]);

            $recipient = MessageRecipient::query()
                ->ofDelivered() // a draft is never the recipient's, even if a stray receipt were trashed
                ->recipientTrashed()
                ->where('recipient_id', $viewerId)
                ->whereIn('message_id', $ids)
                ->update(['recipient_purged_at' => now()]);

            return $sender + $recipient;
        });
    }
}
