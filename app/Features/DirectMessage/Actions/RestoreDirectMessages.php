<?php

namespace App\Features\DirectMessage\Actions;

use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * The viewer is on exactly one side of a message, self-addressing being forbidden, so the two
 * updates are disjoint per id and their counts add up.
 */
class RestoreDirectMessages
{
    /**
     * @param  array<int, int|string>  $messageIds
     * @return int rows restored
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
                ->update(['sender_deleted_at' => null]);

            $recipient = DirectMessageRecipient::query()
                ->ofDelivered() // a draft is never the recipient's, even if a stray receipt were trashed
                ->recipientTrashed()
                ->where('recipient_id', $viewerId)
                ->whereIn('direct_message_id', $ids)
                ->update(['recipient_deleted_at' => null]);

            return $sender + $recipient;
        });
    }
}
