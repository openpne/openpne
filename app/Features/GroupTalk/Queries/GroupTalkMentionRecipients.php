<?php

namespace App\Features\GroupTalk\Queries;

use App\Features\GroupTalk\GroupTalkNotificationEligibility;
use App\Models\Group;
use App\Models\Member;

/**
 * A stored mention may name a member who has since left the group: the row stays and the
 * notification does not.
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
