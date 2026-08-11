<?php

namespace App\Listeners\Timeline;

use App\Features\Timeline\Events\TimelineReplyPosted;
use App\Features\Timeline\Queries\TimelineReplyNotificationRecipients;
use App\Notifications\Timeline\TimelineRepliedNotification;

/**
 * Tells the thread root's author and its other repliers a reply landed. The event's mention
 * snapshot is subtracted from the audience, so a member the reply named hears about it once.
 */
class NotifyTimelineReplyPosted
{
    public function __construct(private readonly TimelineReplyNotificationRecipients $recipients) {}

    public function handle(TimelineReplyPosted $event): void
    {
        foreach (($this->recipients)($event->reply, $event->author, $event->mentionedMemberIds) as [$member, $reason]) {
            $member->notify(
                (new TimelineRepliedNotification($event->author, $event->reply, $reason))
                    ->locale($member->locale ?? app()->getLocale()),
            );
        }
    }
}
