<?php

namespace App\Listeners\CommunityTopic;

use App\Features\CommunityTopic\Events\TopicCommentPosted;
use App\Features\CommunityTopic\Queries\TopicCommentNotificationRecipients;
use App\Notifications\CommunityTopic\TopicCommentedNotification;

class NotifyTopicCommentPosted
{
    public function __construct(private readonly TopicCommentNotificationRecipients $recipients) {}

    public function handle(TopicCommentPosted $event): void
    {
        foreach (($this->recipients)($event->topic, $event->commenter) as [$member, $reason]) {
            $member->notify(
                (new TopicCommentedNotification($event->commenter, $event->topic, $event->comment, $reason))
                    ->locale($member->locale ?? app()->getLocale()),
            );
        }
    }
}
