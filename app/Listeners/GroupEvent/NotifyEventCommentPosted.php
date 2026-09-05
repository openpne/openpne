<?php

namespace App\Listeners\GroupEvent;

use App\Features\GroupEvent\Events\EventCommentPosted;
use App\Features\GroupEvent\Queries\EventCommentNotificationRecipients;
use App\Jobs\BroadcastEventCommentPosted;
use App\Models\GroupEvent;
use App\Notifications\GroupEvent\EventCommentedNotification;

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

        // The excluded ids are snapshotted now, never re-derived when the job runs
        // (docs/internals/notifications.md, "Broadcast fan-out").
        BroadcastEventCommentPosted::dispatch(
            (int) $event->event->getKey(),
            (int) $event->comment->getKey(),
            (int) $event->commenter->getKey(),
            $this->replyRelatedIds($event->event),
        );
    }

    /** @return list<int> */
    private function replyRelatedIds(GroupEvent $event): array
    {
        $ids = $event->comments()->whereNotNull('member_id')->distinct()->pluck('member_id')->all();
        if ($event->member_id !== null) {
            $ids[] = (int) $event->member_id;
        }

        return array_map('intval', $ids);
    }
}
