<?php

namespace App\Listeners\GroupTalk;

use App\Features\GroupTalk\Events\GroupMessagePosted;
use App\Features\GroupTalk\Queries\GroupTalkMentionRecipients;
use App\Notifications\GroupTalk\GroupTalkMentionedNotification;

/** Tells everyone a talk message named that it mentions them — the one notification talk sends. */
class NotifyGroupTalkMentioned
{
    public function __construct(private readonly GroupTalkMentionRecipients $recipients) {}

    public function handleMessagePosted(GroupMessagePosted $event): void
    {
        $recipients = ($this->recipients)($event->message->group, $event->author, $event->mentionedMemberIds);

        foreach ($recipients as $member) {
            $member->notify(
                (new GroupTalkMentionedNotification($event->author, $event->message))
                    ->locale($member->locale ?? app()->getLocale()),
            );
        }
    }
}
