<?php

namespace App\Listeners\CommunityTopic;

use App\Features\CommunityTopic\Events\TopicCommentPosted;
use App\Features\CommunityTopic\Queries\TopicCommentNotificationRecipients;
use App\Jobs\BroadcastTopicCommentPosted;
use App\Notifications\CommunityTopic\TopicCommentedNotification;

class NotifyTopicCommentPosted
{
    public function __construct(private readonly TopicCommentNotificationRecipients $recipients) {}

    public function handle(TopicCommentPosted $event): void
    {
        // Author + co-commenters, notified inline (a small set).
        foreach (($this->recipients)($event->topic, $event->commenter) as [$member, $reason]) {
            $member->notify(
                (new TopicCommentedNotification($event->commenter, $event->topic, $event->comment, $reason))
                    ->locale($member->locale ?? app()->getLocale()),
            );
        }

        // The rest of the community, off the request (CommentNewPost); the job excludes the above.
        BroadcastTopicCommentPosted::dispatch(
            (int) $event->topic->getKey(),
            (int) $event->comment->getKey(),
            (int) $event->commenter->getKey(),
        );
    }
}
