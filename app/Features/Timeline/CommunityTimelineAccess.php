<?php

namespace App\Features\Timeline;

use App\Features\Group\GroupMembership;
use App\Features\GroupTopic\TopicReadAccess;
use App\Models\Group;
use App\Models\Member;

/**
 * The authorization chokepoint for a community's timeline. OpenPNE 3 gated it by login alone — its
 * feed required the *author* to be a member, never the viewer — which would leave a MembersOnly
 * community's timeline readable while its board is not. OpenPNE 4 reads the same access column the
 * board and events read, so one community answers "who may read this" the same way everywhere.
 *
 * Posting follows membership alone: topic_post_authority is deliberately not consulted, so an
 * admins-only board does not also silence the community's timeline.
 */
class CommunityTimelineAccess
{
    /** May the member read this community's timeline? MembersOnly requires membership. */
    public static function canViewTimeline(Group $group, Member $member): bool
    {
        if ($group->topic_read_access === TopicReadAccess::MembersOnly) {
            return GroupMembership::isMember($group, $member);
        }

        return true;
    }

    /**
     * May the member post or reply here? Membership, whatever the read gate — an Everyone community
     * is readable by any member but writable only by its own (OpenPNE 3 checkGroupMember).
     */
    public static function canPost(Group $group, Member $member): bool
    {
        return GroupMembership::isMember($group, $member);
    }
}
