<?php

namespace App\Features\DirectMessage\Actions;

use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * Purge only ever follows trash, so it sets `*_purged_at` where `*_deleted_at` already is, keeping
 * the purged ⇒ deleted invariant the boxes read by. The row and any attached file bytes stay, since
 * the other side may still hold the message and a purged side only loses its view (OpenPNE 3 left
 * the bytes too).
 */
class PurgeDirectMessages
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
            $sender = DirectMessage::query()
                ->senderTrashed()
                ->where('sender_id', $viewerId)
                ->whereIn('id', $ids)
                ->update(['sender_purged_at' => now()]);

            $recipient = DirectMessageRecipient::query()
                ->ofDelivered() // a draft is never the recipient's, even if a stray receipt were trashed
                ->recipientTrashed()
                ->where('recipient_id', $viewerId)
                ->whereIn('direct_message_id', $ids)
                ->update(['recipient_purged_at' => now()]);

            return $sender + $recipient;
        });
    }
}
