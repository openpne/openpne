<?php

namespace App\Listeners\Timeline;

use App\Features\Timeline\Events\TimelinePostPosted;
use App\Jobs\BroadcastCommunityTimelinePosted;
use App\Jobs\BroadcastTimelinePosted;

/**
 * Hands the new-post fan-out to a queued job: the audience can be member-wide, so it must not run in
 * the request. Only the post id and the mention snapshot cross to the job, which re-reads the post
 * (and no-ops if it is already gone). The event fires for top-level posts only; a reply notifies
 * through NotifyTimelineReplyPosted.
 *
 * The two fan-outs are exclusive. A community post has the community for an audience and its own
 * opt-out kind; sending it through the SNS-wide job as well would reach an everyone-readable
 * community's members twice, under a kind they did not choose it by.
 */
class NotifyTimelinePosted
{
    public function handle(TimelinePostPosted $event): void
    {
        $postId = (int) $event->post->getKey();

        if ($event->post->community_id !== null) {
            BroadcastCommunityTimelinePosted::dispatch($postId, $event->mentionedMemberIds);

            return;
        }

        BroadcastTimelinePosted::dispatch($postId, $event->mentionedMemberIds);
    }
}
