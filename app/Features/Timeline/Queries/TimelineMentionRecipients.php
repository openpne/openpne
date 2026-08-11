<?php

namespace App\Features\Timeline\Queries;

use App\Features\Timeline\TimelineNotificationEligibility;
use App\Models\Member;
use App\Models\TimelinePost;

/**
 * Who a post's stored @mentions notify: the members it named, minus anyone who may no longer
 * receive it (TimelineNotificationEligibility), never the author.
 *
 * Ban and block are re-checked rather than trusted from write time, which is a moment earlier than
 * delivery. Viewability is checked only here — storage lets a member be mentioned in a post they
 * cannot read, which reads as the plain text it is, but must not become a notification about it.
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
            if (TimelineNotificationEligibility::canReceive($member, $post, $author)) {
                $recipients[] = $member;
            }
        }

        return $recipients;
    }
}
