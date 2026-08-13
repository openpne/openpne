<?php

namespace App\Listeners\Group;

use App\Features\Group\Events\GroupJoined;
use App\Features\Group\Queries\GroupJoinNotificationRecipients;
use App\Notifications\Group\GroupJoinedNotification;

class NotifyGroupJoined
{
    public function __construct(private readonly GroupJoinNotificationRecipients $recipients) {}

    public function handle(GroupJoined $event): void
    {
        foreach (($this->recipients)($event->group, $event->member) as $admin) {
            $admin->notify(
                (new GroupJoinedNotification($event->group, $event->member))
                    ->locale($admin->locale ?? app()->getLocale()),
            );
        }
    }
}
