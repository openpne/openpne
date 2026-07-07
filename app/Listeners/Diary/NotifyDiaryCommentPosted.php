<?php

namespace App\Listeners\Diary;

use App\Features\Diary\Events\DiaryCommentPosted;
use App\Features\Diary\Queries\DiaryCommentNotificationRecipients;
use App\Notifications\Diary\DiaryCommentedNotification;

class NotifyDiaryCommentPosted
{
    public function __construct(private readonly DiaryCommentNotificationRecipients $recipients) {}

    public function handle(DiaryCommentPosted $event): void
    {
        foreach (($this->recipients)($event->diary, $event->commenter) as [$member, $reason]) {
            $member->notify(
                (new DiaryCommentedNotification($event->commenter, $event->diary, $event->comment, $reason))
                    ->locale($member->locale ?? app()->getLocale()),
            );
        }
    }
}
