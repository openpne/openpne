<?php

namespace App\Features\Timeline\Queries;

use App\Features\Timeline\TimelineNotificationEligibility;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Notifications\CommentReason;

/**
 * Who a new reply notifies: the thread root's author (Reply) and the distinct other members who
 * already replied to that root (Related), never the replier. One entry per recipient, Reply winning
 * when both apply.
 *
 * Every recipient must still be able to receive it (TimelineNotificationEligibility: unbanned, able
 * to view the thread root, no block either way against the replier). Members the reply @mentions are
 * excluded by the caller's snapshot, so the precedence is Mention > Reply > Related.
 */
class TimelineReplyNotificationRecipients
{
    /**
     * @param  list<int>  $excludeMemberIds  members already notified about this reply (its @mentions)
     * @return list<array{0: Member, 1: CommentReason}>
     */
    public function __invoke(TimelinePost $reply, Member $replier, array $excludeMemberIds = []): array
    {
        $root = $reply->parent;
        if ($root === null) {
            return [];
        }

        $excluded = array_flip($excludeMemberIds);

        // A withdrawn member's posts cascade away, so there is no null author to filter here.
        $coReplierIds = TimelinePost::query()
            ->where('in_reply_to_id', $root->getKey())
            ->where('member_id', '!=', $replier->getKey())
            ->where('member_id', '!=', $root->member_id)
            ->distinct()
            ->pluck('member_id');

        $recipients = [];

        $owner = $root->member;
        if ($owner !== null
            && ! isset($excluded[$owner->getKey()])
            && TimelineNotificationEligibility::canReceive($owner, $reply, $replier)
        ) {
            $recipients[] = [$owner, CommentReason::Reply];
        }

        $others = $coReplierIds->reject(fn ($id): bool => isset($excluded[$id]));
        foreach (Member::query()->findMany($others) as $member) {
            if (TimelineNotificationEligibility::canReceive($member, $reply, $replier)) {
                $recipients[] = [$member, CommentReason::Related];
            }
        }

        return $recipients;
    }
}
