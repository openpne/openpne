<?php

namespace App\Features\Notifications\Queries;

use App\Models\Member;

/**
 * Driven by `read_at`, unlike the layer-1 counts (docs/internals/notifications.md, "The three
 * layers").
 */
class CountUnreadNotifications
{
    public function __invoke(Member $viewer): int
    {
        return VisibleNotifications::apply($viewer->unreadNotifications())->count();
    }
}
