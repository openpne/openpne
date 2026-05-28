<?php

namespace App\Listeners\Friend;

use App\Features\Friend\Events\FriendRequestAccepted;
use App\Notifications\Friend\FriendRequestAcceptedNotification;

class NotifyFriendRequestAccepted
{
    public function handle(FriendRequestAccepted $event): void
    {
        $event->requester->notify(new FriendRequestAcceptedNotification($event->accepter));
    }
}
