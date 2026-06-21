<?php

namespace App\Features\Message;

use App\Features\Block\BlockLookup;
use App\Models\Member;
use App\Models\Message;

/**
 * Authorization choicepoint for private messages. Used by the controller, the queries, and
 * FilePolicy (for attachment bytes), so the "who may see this message" rule lives in one place.
 */
class MessageAccess
{
    /**
     * May $viewer read this message (its body and attachments)? The sender always may — including
     * their own draft — until they purge it. A recipient may read a delivered (non-draft) message
     * they have not purged; a draft's intended recipient may not, so a draft attachment is never
     * fetchable through the shared file route. Purge revokes the purging side's view (an old file
     * URL stops resolving), distinct from the row/bytes that stay for the other side.
     */
    public static function canViewMessage(Message $message, Member $viewer): bool
    {
        if ((int) $message->sender_id === (int) $viewer->getKey()) {
            return $message->sender_purged_at === null;
        }

        if ($message->is_draft) {
            return false;
        }

        return $message->recipients()
            ->where('recipient_id', $viewer->getKey())
            ->whereNull('recipient_purged_at')
            ->exists();
    }

    /**
     * May $sender send a message to $recipient? OpenPNE 3 404s a self-addressed message; a blocked
     * pair (either direction) cannot message; a login-rejected (banned) member cannot receive.
     * ($recipient `is_active` has no OpenPNE 4 column — is_login_rejected is the carried gate.)
     */
    public static function canSend(Member $sender, Member $recipient): bool
    {
        return $sender->isNot($recipient)
            && ! $recipient->is_login_rejected
            && ! BlockLookup::hasAnyBlockBetween($sender, $recipient);
    }
}
