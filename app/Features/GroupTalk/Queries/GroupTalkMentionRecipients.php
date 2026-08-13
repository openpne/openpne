<?php

namespace App\Features\GroupTalk\Queries;

use App\Features\GroupTalk\GroupTalkNotificationEligibility;
use App\Models\Group;
use App\Models\Member;

/**
 * Who a message's stored @mentions notify: the members it named, minus anyone who may no longer
 * receive it (GroupTalkNotificationEligibility), never the author.
 *
 * Ban, block and membership are re-checked rather than trusted from write time, which is already a
 * moment earlier than delivery. Storage lets a member be mentioned in a group they have since left —
 * the range renders as the plain text it is — but that must not become a notification about it.
 */
class GroupTalkMentionRecipients
{
    /**
     * @param  list<int>  $mentionedMemberIds  the distinct members the message's mentions name
     * @return list<Member>
     */
    public function __invoke(Group $group, Member $author, array $mentionedMemberIds): array
    {
        if ($mentionedMemberIds === []) {
            return [];
        }

        $recipients = [];

        foreach (Member::query()->findMany($mentionedMemberIds) as $member) {
            if (GroupTalkNotificationEligibility::canReceive($member, $group, $author)) {
                $recipients[] = $member;
            }
        }

        return $recipients;
    }
}
