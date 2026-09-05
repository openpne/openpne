<?php

namespace App\Features\Timeline;

use App\Features\Block\BlockLookup;
use App\Models\Member;
use App\Models\TimelinePost;

/**
 * The one predicate behind every timeline notification, evaluated again in each notification's
 * `shouldSend()` because a queued one can outlive the facts it was enqueued under
 * (docs/internals/notifications.md, "Delivery-time re-checks"). Viewability is judged on the thread
 * root, whose author owns the thread's one audience — {@see TimelineAccess} would otherwise read
 * the owner off a reply.
 */
final class TimelineNotificationEligibility
{
    public static function canReceive(Member $recipient, TimelinePost $post, Member $author): bool
    {
        $root = self::threadRoot($post);
        if ($root === null) {
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
