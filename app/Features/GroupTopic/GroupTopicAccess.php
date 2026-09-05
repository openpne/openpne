<?php

namespace App\Features\GroupTopic;

use App\Features\Group\GroupMembership;
use App\Models\Group;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;

/**
 * Ports OpenPNE 3's opCommunityTopicAclBuilder + opIsCreatableCommunityTopicBehavior. Every
 * decision flows through GroupMembership so it cannot drift from what a member is to a group.
 */
class GroupTopicAccess
{
    /** Everyone admits any signed-in member, not the public (OpenPNE 3 public_flag). */
    public static function canViewBoard(Group $group, Member $member): bool
    {
        if ($group->topic_read_access === TopicReadAccess::MembersOnly) {
            return GroupMembership::isMember($group, $member);
        }

        return true;
    }

    public static function canViewTopic(GroupTopic $topic, Member $member): bool
    {
        return self::canViewBoard($topic->group, $member);
    }

    /** Gates posting topics only — commenting is open to any member (OpenPNE 3 topic_authority). */
    public static function canPostTopic(Group $group, Member $member): bool
    {
        if ($group->topic_post_authority === TopicPostAuthority::AdminsOnly) {
            return GroupMembership::isAdmin($group, $member);
        }

        return GroupMembership::isMember($group, $member);
    }

    /** The author may, but only while still a member (OpenPNE 3 isEditable checks membership first). */
    public static function canEditTopic(GroupTopic $topic, Member $member): bool
    {
        $group = $topic->group;

        if (! GroupMembership::isMember($group, $member)) {
            return false;
        }

        return $member->getKey() === $topic->member_id
            || GroupMembership::isAdmin($group, $member);
    }

    public static function canComment(GroupTopic $topic, Member $member): bool
    {
        return GroupMembership::isMember($topic->group, $member);
    }

    /** Its author may unless withdrawn, or anyone who may edit the topic (OpenPNE 3 isDeletable). */
    public static function canDeleteComment(GroupTopicComment $comment, Member $member): bool
    {
        if ($comment->member_id !== null && $member->getKey() === $comment->member_id) {
            return true;
        }

        return self::canEditTopic($comment->topic, $member);
    }
}
