<?php

namespace App\Listeners\Friend;

use App\Features\Friend\Events\FriendRequested;
use App\Notifications\Friend\FriendRequestedNotification;

class NotifyFriendRequested
{
    public function handle(FriendRequested $event): void
    {
        $event->target->notify(new FriendRequestedNotification($event->requester));
    }
}
