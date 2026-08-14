<?php

namespace App\Features\DirectMessage\Actions;

use App\Features\DirectMessage\ConversationScope;
use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Take a whole conversation off the viewer's screens: everything both arms hold, trashed and purged
 * on the viewer's own side, in one write per arm. The counterpart keeps their copies whole —
 * visibility is per-side (docs/internals/direct-messages.md) — so this ends a correspondence for one
 * reader and retracts nothing.
 *
 * Trash and purge move together. The mailbox's trash is somewhere to change your mind about one
 * message; answering "delete this conversation" by moving all of it there would be answering a
 * different question. Setting both columns in the same statement is also what keeps purged ⇒
 * deleted, the invariant the boxes read by.
 *
 * A draft is not in it: a draft has no receipt, so it belongs to no conversation, and it is still
 * being written.
 *
 * Set-based like the trash actions, and idempotent — an already-purged row keeps its first purge
 * time, a second call moves nothing, and a conversation with nothing left in it is not an error to
 * delete.
 */
class DeleteConversation
{
    /**
     * @param  Member|null  $counterpart  null = the withdrawn bucket
     * @return int rows moved on the viewer's side
     */
    public function __invoke(Member $viewer, ?Member $counterpart): int
    {
        // One instant for the whole conversation rather than one per statement.
        $at = now();

        return DB::transaction(function () use ($viewer, $counterpart, $at): int {
            $sent = DirectMessage::query()->senderLive();
            // An upgraded OpenPNE 3 send to several people is one authored row with one sender-side
            // column, so deleting one of those conversations takes the message out of the others
            // the viewer sent it to. There is no per-recipient half of the sender's copy to move.
            ConversationScope::outbound($sent, $viewer, $counterpart);

            $received = DirectMessageRecipient::query()
                ->where('recipient_id', $viewer->getKey())
                ->recipientLive()
                ->whereHas('directMessage', fn (Builder $message) => ConversationScope::inbound($message, $counterpart));

            return $sent->update(['sender_deleted_at' => $at, 'sender_purged_at' => $at])
                + $received->update(['recipient_deleted_at' => $at, 'recipient_purged_at' => $at]);
        });
    }
}
