<?php

namespace App\Features\Timeline;

use App\Features\Block\BlockLookup;
use App\Features\Group\GroupMembership;
use App\Models\Member;
use App\Models\TimelinePost;

/**
 * Whether a member may receive a notification about a timeline post right now — the one predicate
 * behind every timeline notification (mention, new post, reply), so the three cannot drift.
 *
 * Evaluated twice per recipient: when the listener or fan-out enqueues, and again in the
 * notification's shouldSend() immediately before each channel delivers, because a queued
 * notification can outlive the facts it was enqueued under (a ban, a new block, a revoked
 * friendship on a Friends thread) and a mail carries the post body.
 *
 * Viewability is judged on the thread root. A reply inherits its parent's visibility, so a thread
 * is one audience owned by the root's author — while TimelineAccess reads the owner off the row it
 * is handed, which for a reply is the replier, a stranger to that audience.
 */
final class TimelineNotificationEligibility
{
    public static function canReceive(Member $recipient, TimelinePost $post, Member $author): bool
    {
        $root = self::threadRoot($post);
        if ($root === null) {
            return false;
        }

        // A community thread notifies its community, not everyone who could read it. Viewability
        // alone would keep telling an ex-member about an everyone-readable community they left,
        // and the opt-out they would reach for is that community kind — which is not theirs to
        // hold once they are outside it.
        if ($root->community_id !== null && ! GroupMembership::isMember($root->community, $recipient)) {
            return false;
        }

        return ! $recipient->is($author)
            && ! $recipient->is_login_rejected
            && TimelineAccess::canView($recipient, $root)
            && ! BlockLookup::hasAnyBlockBetween($recipient, $author);
    }

    /** The row a thread is addressed by; a top-level post is its own root. */
    public static function threadRoot(TimelinePost $post): ?TimelinePost
    {
        return $post->in_reply_to_id === null ? $post : $post->parent;
    }
}
