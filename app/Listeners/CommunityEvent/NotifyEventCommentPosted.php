<?php

namespace App\Listeners\CommunityEvent;

use App\Features\CommunityEvent\Events\EventCommentPosted;
use App\Features\CommunityEvent\Queries\EventCommentNotificationRecipients;
use App\Notifications\CommunityEvent\EventCommentedNotification;

class NotifyEventCommentPosted
{
    public function __construct(private readonly EventCommentNotificationRecipients $recipients) {}

    public function handle(EventCommentPosted $event): void
    {
        foreach (($this->recipients)($event->event, $event->commenter) as [$member, $reason]) {
            $member->notify(
                (new EventCommentedNotification($event->commenter, $event->event, $event->comment, $reason))
                    ->locale($member->locale ?? app()->getLocale()),
            );
        }
    }
}
