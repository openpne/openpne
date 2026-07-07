<?php

namespace App\Features\Notifications\Queries;

use App\Models\Member;

/**
 * The bell badge number: unread rows in the member's per-event feed (layer 3). Unlike the
 * layer-1 counts this is read_at-driven — reading the feed drops it, acting on the underlying
 * item does not.
 */
class CountUnreadNotifications
{
    public function __invoke(Member $viewer): int
    {
        return $viewer->unreadNotifications()->count();
    }
}
