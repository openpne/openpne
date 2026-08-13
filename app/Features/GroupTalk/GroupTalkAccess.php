<?php

namespace App\Features\GroupTalk;

use App\Features\Group\GroupMembership;
use App\Features\GroupTopic\TopicReadAccess;
use App\Models\Group;
use App\Models\Member;

/**
 * The authorization chokepoint for a group's talk, succeeding CommunityTimelineAccess. Reading is
 * the group's own read column — the same one the board and events read, so one group answers "who
 * may read this" the same way everywhere, and a group whose old timeline was readable by any member
 * does not lose that audience when its history becomes talk.
 *
 * Posting follows membership alone: topic_post_authority is deliberately not consulted, so an
 * admins-only board does not also silence the group's chat. An Everyone group is therefore readable
 * by any member and writable only by its own — a Slack public channel, not a broadcast.
 *
 * Talk history applies NO per-row filter, and that is a contract rather than an omission. The
 * community timeline this replaces hid a row whose author had since left the group, and a row whose
 * author had blocked the viewer. Talk hides neither: a conversation with holes in it is not the
 * conversation that happened, and the group boards it sits beside — topic and event comments —
 * have never filtered either. Blocking still does its work where a block is about people rather
 * than about a room: mention eligibility, mention candidates, and member pages.
 */
class GroupTalkAccess
{
    /** May the member read this group's talk? MembersOnly requires membership. */
    public static function canView(Group $group, Member $member): bool
    {
        if ($group->topic_read_access === TopicReadAccess::MembersOnly) {
            return GroupMembership::isMember($group, $member);
        }

        return true;
    }

    /** May the member post here? Membership, whatever the read gate. */
    public static function canPost(Group $group, Member $member): bool
    {
        return GroupMembership::isMember($group, $member);
    }
}
