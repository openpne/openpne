<?php

namespace App\Features\DirectMessage\Queries;

use App\Models\DirectMessageRecipient;
use App\Models\Member;

/**
 * The badge reads the inbox box's own scopes, so it cannot disagree with the box about what counts.
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
