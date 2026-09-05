<?php

namespace App\Features\GroupTalk;

use App\Features\Group\GroupMembership;
use App\Features\GroupTopic\TopicReadAccess;
use App\Models\Group;
use App\Models\Member;

/**
 * Posting follows membership alone; `topic_post_authority` is deliberately not consulted, so an
 * admins-only board does not silence the group's chat. Talk history applies no per-row filter —
 * neither the author's membership nor a block (docs/internals/group-talk.md, "History carries no
 * per-row filter").
 */
class GroupTalkAccess
{
    public static function canView(Group $group, Member $member): bool
    {
        if ($group->topic_read_access === TopicReadAccess::MembersOnly) {
            return GroupMembership::isMember($group, $member);
        }

        return true;
    }

    public static function canPost(Group $group, Member $member): bool
    {
        return GroupMembership::isMember($group, $member);
    }
}
