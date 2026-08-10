<?php

namespace App\Features\Timeline\Queries;

use App\Features\Block\BlockLookup;
use App\Features\Timeline\TimelineAccess;
use App\Models\Member;
use App\Models\TimelinePost;

/**
 * Who a post's stored @mentions notify: the members it named, minus anyone who may no longer
 * receive it, never the author.
 *
 * Three conditions: not banned (banned members cannot receive, as in messaging), able to view the
 * post right now, and with no block in either direction against the author. Ban and block are
 * re-checked rather than trusted from write time, which is a moment earlier than delivery.
 * Viewability is checked only here — storage lets a member be mentioned in a post they cannot
 * read, which reads as the plain text it is, but must not become a notification about it.
 *
 * Viewability is judged on the thread root. A reply inherits its parent's visibility, so a thread
 * is one audience owned by the root's author — while TimelineAccess reads the owner off the row it
 * is handed, which for a reply is the replier, a stranger to that audience.
 */
class TimelineMentionRecipients
{
    /**
     * @param  list<int>  $mentionedMemberIds  the distinct members the post's mentions name
     * @return list<Member>
     */
    public function __invoke(TimelinePost $post, Member $author, array $mentionedMemberIds): array
    {
        if ($mentionedMemberIds === []) {
            return [];
        }

        $recipients = [];

        foreach (Member::query()->findMany($mentionedMemberIds) as $member) {
            if ($this->eligible($member, $post, $author)) {
                $recipients[] = $member;
            }
        }

        return $recipients;
    }

    /**
     * Whether $recipient may receive a mention notification about $post right now. Evaluated twice
     * per mention — when the listener enqueues, and again in the notification's shouldSend()
     * immediately before each channel delivers — because a queued notification can outlive the
     * facts it was enqueued under (a ban, a new block, a revoked friendship on a Friends thread).
     */
    public function eligible(Member $recipient, TimelinePost $post, Member $author): bool
    {
        $root = $post->in_reply_to_id === null ? $post : $post->parent;
        if ($root === null) {
            return false;
        }

        // Storage already drops a self-mention; this holds the line if that ever changes.
        return ! $recipient->is($author)
            && ! $recipient->is_login_rejected
            && TimelineAccess::canView($recipient, $root)
            && ! BlockLookup::hasAnyBlockBetween($recipient, $author);
    }
}
