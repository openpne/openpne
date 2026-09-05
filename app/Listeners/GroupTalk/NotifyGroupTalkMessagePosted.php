<?php

namespace App\Listeners\GroupTalk;

use App\Features\GroupTalk\Events\GroupMessagePosted;
use App\Jobs\BroadcastGroupMessagePosted;

/**
 * Queued because the audience is the whole room, with a delay so a member reading right now marks it read
 * first. The mention snapshot crosses to the job — the members actually notified, never a set re-derived
 * later.
 */
class NotifyGroupTalkMessagePosted
{
    public function handleMessagePosted(GroupMessagePosted $event): void
    {
        BroadcastGroupMessagePosted::dispatch((int) $event->message->getKey(), $event->mentionedMemberIds)
            ->delay(now()->addSeconds(BroadcastGroupMessagePosted::GRACE_SECONDS));
    }
}
