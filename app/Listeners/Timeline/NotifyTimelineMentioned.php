<?php

namespace App\Listeners\Timeline;

use App\Features\Timeline\Events\TimelinePostPosted;
use App\Features\Timeline\Events\TimelineReplyPosted;
use App\Features\Timeline\Queries\TimelineMentionRecipients;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Notifications\Timeline\TimelineMentionedNotification;

/** Both events carry the same snapshot and a mention reads the same either way, so one listener serves both. */
class NotifyTimelineMentioned
{
    public function __construct(private readonly TimelineMentionRecipients $recipients) {}

    public function handlePostPosted(TimelinePostPosted $event): void
    {
        $this->notify($event->post, $event->author, $event->mentionedMemberIds);
    }

    public function handleReplyPosted(TimelineReplyPosted $event): void
    {
        $this->notify($event->reply, $event->author, $event->mentionedMemberIds);
    }

    /** @param  list<int>  $mentionedMemberIds */
    private function notify(TimelinePost $post, Member $author, array $mentionedMemberIds): void
    {
        foreach (($this->recipients)($post, $author, $mentionedMemberIds) as $member) {
            $member->notify(
                (new TimelineMentionedNotification($author, $post))
                    ->locale($member->locale ?? app()->getLocale()),
            );
        }
    }
}
