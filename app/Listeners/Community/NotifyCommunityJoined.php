<?php

namespace App\Listeners\Community;

use App\Features\Community\Events\CommunityJoined;
use App\Features\Community\Queries\CommunityJoinNotificationRecipients;
use App\Notifications\Community\CommunityJoinedNotification;

class NotifyCommunityJoined
{
    public function __construct(private readonly CommunityJoinNotificationRecipients $recipients) {}

    public function handle(CommunityJoined $event): void
    {
        foreach (($this->recipients)($event->community, $event->member) as $admin) {
            $admin->notify(
                (new CommunityJoinedNotification($event->community, $event->member))
                    ->locale($admin->locale ?? app()->getLocale()),
            );
        }
    }
}
