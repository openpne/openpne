<?php

namespace App\Listeners\Timeline;

use App\Features\Timeline\Events\TimelinePostPosted;
use App\Jobs\BroadcastTimelinePosted;

/**
 * Queued because the audience can be member-wide and must not be walked in the request. The event fires
 * for top-level posts only; a reply notifies through NotifyTimelineReplyPosted.
 */
class NotifyTimelinePosted
{
    public function handle(TimelinePostPosted $event): void
    {
        BroadcastTimelinePosted::dispatch((int) $event->post->getKey(), $event->mentionedMemberIds);
    }
}
