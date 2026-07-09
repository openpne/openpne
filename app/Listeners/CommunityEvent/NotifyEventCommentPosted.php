<?php

namespace App\Listeners\CommunityEvent;

use App\Features\CommunityEvent\Events\EventCommentPosted;
use App\Features\CommunityEvent\Queries\EventCommentNotificationRecipients;
use App\Jobs\BroadcastEventCommentPosted;
use App\Notifications\CommunityEvent\EventCommentedNotification;

class NotifyEventCommentPosted
{
    public function __construct(private readonly EventCommentNotificationRecipients $recipients) {}

    public function handle(EventCommentPosted $event): void
    {
        // Author + co-commenters, notified inline (a small set).
        foreach (($this->recipients)($event->event, $event->commenter) as [$member, $reason]) {
            $member->notify(
                (new EventCommentedNotification($event->commenter, $event->event, $event->comment, $reason))
                    ->locale($member->locale ?? app()->getLocale()),
            );
        }

        // The rest of the community, off the request (CommentNewPost); the job excludes the above.
        BroadcastEventCommentPosted::dispatch(
            (int) $event->event->getKey(),
            (int) $event->comment->getKey(),
            (int) $event->commenter->getKey(),
        );
    }
}
