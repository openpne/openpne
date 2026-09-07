<?php

namespace App\Listeners\Timeline;

use App\Features\Timeline\Events\TimelinePostPosted;
use App\Features\Timeline\TimelinePostOrigin;
use App\Jobs\BroadcastTimelinePosted;

/**
 * Queued because the audience can be member-wide and must not be walked in the request. The event fires
 * for top-level posts only; a reply notifies through NotifyTimelineReplyPosted.
 */
class NotifyTimelinePosted
{
    public function handle(TimelinePostPosted $event): void
    {
        // An announcement's subject already notified its own audience (a diary the same one, a
        // topic or event its group), so the line adds no fan-out of its own.
        if ($event->origin === TimelinePostOrigin::Auto) {
            return;
        }

        BroadcastTimelinePosted::dispatch((int) $event->post->getKey(), $event->mentionedMemberIds);
    }
}
