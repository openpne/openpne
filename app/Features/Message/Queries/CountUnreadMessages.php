<?php

namespace App\Features\Message\Queries;

use App\Models\Member;
use App\Models\MessageRecipient;

/**
 * How many delivered messages sit unread in this member's inbox. Shares the inbox's box predicate
 * (delivered, live) with ListMessages::received so the badge and the inbox agree on what counts;
 * unread is read_at IS NULL.
 */
class CountUnreadMessages
{
    public function __invoke(Member $viewer): int
    {
        return MessageRecipient::query()
            ->ofDelivered()
            ->recipientLive()
            ->where('recipient_id', $viewer->getKey())
            ->whereNull('read_at')
            ->count();
    }
}
