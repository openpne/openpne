<?php

namespace App\Listeners\GroupTopic;

use App\Features\GroupTopic\Events\TopicCommentPosted;
use App\Features\GroupTopic\Queries\TopicCommentNotificationRecipients;
use App\Jobs\BroadcastTopicCommentPosted;
use App\Models\GroupTopic;
use App\Notifications\GroupTopic\TopicCommentedNotification;

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

        // The excluded ids are snapshotted now, never re-derived when the job runs
        // (docs/internals/notifications.md, "Broadcast fan-out").
        BroadcastTopicCommentPosted::dispatch(
            (int) $event->topic->getKey(),
            (int) $event->comment->getKey(),
            (int) $event->commenter->getKey(),
            $this->replyRelatedIds($event->topic),
        );
    }

    /** @return list<int> */
    private function replyRelatedIds(GroupTopic $topic): array
    {
        $ids = $topic->comments()->whereNotNull('member_id')->distinct()->pluck('member_id')->all();
        if ($topic->member_id !== null) {
            $ids[] = (int) $topic->member_id;
        }

        return array_map('intval', $ids);
    }
}
