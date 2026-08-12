<?php

namespace App\Features\DirectMessage\Queries;

use App\Models\DirectMessageRecipient;
use App\Models\Member;

/**
 * How many delivered messages sit unread in this member's inbox. Shares the inbox's box predicate
 * (delivered, live) with ListDirectMessages::received so the badge and the inbox agree on what counts;
 * unread is read_at IS NULL.
 */
class CountUnreadDirectMessages
{
    public function __invoke(Member $viewer): int
    {
        return DirectMessageRecipient::query()
            ->ofDelivered()
            ->recipientLive()
            ->where('recipient_id', $viewer->getKey())
            ->whereNull('read_at')
            ->count();
    }
}
