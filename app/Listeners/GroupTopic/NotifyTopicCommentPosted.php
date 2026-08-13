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
        // Author + co-commenters, notified inline (a small set).
        foreach (($this->recipients)($event->topic, $event->commenter) as [$member, $reason]) {
            $member->notify(
                (new TopicCommentedNotification($event->commenter, $event->topic, $event->comment, $reason))
                    ->locale($member->locale ?? app()->getLocale()),
            );
        }

        // The rest of the group, off the request (CommentNewPost). Snapshot the author + co-commenter
        // ids now (the Reply/Related lane) so the async broadcast excludes exactly them even if a comment
        // is deleted before it runs — otherwise a dropped co-commenter would be notified twice.
        BroadcastTopicCommentPosted::dispatch(
            (int) $event->topic->getKey(),
            (int) $event->comment->getKey(),
            (int) $event->commenter->getKey(),
            $this->replyRelatedIds($event->topic),
        );
    }

    /**
     * The author + everyone who has commented — the members handled by the inline Reply/Related lane.
     *
     * @return list<int>
     */
    private function replyRelatedIds(GroupTopic $topic): array
    {
        $ids = $topic->comments()->whereNotNull('member_id')->distinct()->pluck('member_id')->all();
        if ($topic->member_id !== null) {
            $ids[] = (int) $topic->member_id;
        }

        return array_map('intval', $ids);
    }
}
