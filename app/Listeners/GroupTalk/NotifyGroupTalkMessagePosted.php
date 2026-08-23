<?php

namespace App\Listeners\GroupTalk;

use App\Features\GroupTalk\Events\GroupMessagePosted;
use App\Jobs\BroadcastGroupMessagePosted;

/**
 * Hands the per-message broadcast to a queued job: the audience is the whole room, so it must not run
 * in the request, and the delay gives a member who is reading right now the chance to mark the room
 * read before the job asks whether they still need telling.
 *
 * The mention snapshot crosses to the job as it does for the timeline: the members that were actually
 * notified, never a set re-derived later.
 */
class NotifyGroupTalkMessagePosted
{
    public function handleMessagePosted(GroupMessagePosted $event): void
    {
        BroadcastGroupMessagePosted::dispatch((int) $event->message->getKey(), $event->mentionedMemberIds)
            ->delay(now()->addSeconds(BroadcastGroupMessagePosted::GRACE_SECONDS));
    }
}
