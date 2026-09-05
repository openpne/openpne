<?php

namespace App\Features\DirectMessage\Actions;

use App\Features\DirectMessage\ConversationScope;
use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Trash and purge are set in one statement per arm, which is what keeps the purged ⇒ deleted
 * invariant the boxes read by (`docs/internals/direct-messages.md`, "Deleting a conversation").
 */
class DeleteConversation
{
    /**
     * @param  Member|null  $counterpart  null = the withdrawn bucket
     * @return int rows moved on the viewer's side
     */
    public function __invoke(Member $viewer, ?Member $counterpart): int
    {
        $at = now();

        return DB::transaction(function () use ($viewer, $counterpart, $at): int {
            $sent = DirectMessage::query()->senderLive();
            // An upgraded OpenPNE 3 send to several people is one authored row with one sender-side
            // column, so this takes the message out of the other conversations it was sent to.
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
