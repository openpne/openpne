<?php

namespace App\Features\DirectMessage;

use App\Features\Block\BlockLookup;
use App\Models\DirectMessage;
use App\Models\Member;

/** The one place that answers who may see a private message, its attachment bytes included. */
class DirectMessageAccess
{
    /**
     * A draft's intended recipient may not read it, so a draft's attachment is not fetchable through
     * the file route either. Purge revokes the purging side's view — an old file URL stops resolving
     * — while the row and its bytes stay for the other side.
     */
    public static function canViewMessage(DirectMessage $message, Member $viewer): bool
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
     * A block in either direction refuses the pair, and a login-rejected member cannot receive.
     * OpenPNE 3's `is_active` has no OpenPNE 4 column; `is_login_rejected` is the carried gate.
     */
    public static function canSend(Member $sender, Member $recipient): bool
    {
        return $sender->isNot($recipient)
            && ! $recipient->is_login_rejected
            && ! BlockLookup::hasAnyBlockBetween($sender, $recipient);
    }
}
