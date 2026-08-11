<?php

namespace App\Listeners\Timeline;

use App\Features\Timeline\Events\TimelinePostPosted;
use App\Jobs\BroadcastTimelinePosted;

/**
 * Hands the new-post fan-out to a queued job: the audience can be member-wide, so it must not run in
 * the request. Only the post id and the mention snapshot cross to the job, which re-reads the post
 * (and no-ops if it is already gone). The event fires for top-level posts only; a reply notifies
 * through NotifyTimelineReplyPosted.
 */
class NotifyTimelinePosted
{
    public function handle(TimelinePostPosted $event): void
    {
        BroadcastTimelinePosted::dispatch((int) $event->post->getKey(), $event->mentionedMemberIds);
    }
}
