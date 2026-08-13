<?php

namespace App\Features\GroupTopic;

use App\Features\Group\GroupMembership;
use App\Models\Group;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;

/**
 * The single authorization chokepoint for the group topic board, porting OpenPNE 3's
 * opCommunityTopicAclBuilder + opIsCreatableCommunityTopicBehavior. Read/post gates depend on the
 * group's two access columns; edit/delete depend on authorship and role. Every decision flows
 * through GroupMembership so it cannot drift from "what is this member to this group".
 */
class GroupTopicAccess
{
    /**
     * May the member read this group's board (list + show)? MembersOnly requires membership;
     * Everyone admits any signed-in member (OpenPNE 3 public_flag).
     */
    public static function canViewBoard(Group $group, Member $member): bool
    {
        if ($group->topic_read_access === TopicReadAccess::MembersOnly) {
            return GroupMembership::isMember($group, $member);
        }

        return true;
    }

    /** Read access for a single topic — the same gate as the board it belongs to. */
    public static function canViewTopic(GroupTopic $topic, Member $member): bool
    {
        return self::canViewBoard($topic->group, $member);
    }

    /**
     * May the member post a topic? AdminsOnly requires admin; Members requires membership
     * (OpenPNE 3 topic_authority). Note this gates posting topics only — commenting is open to any
     * member regardless (see canComment).
     */
    public static function canPostTopic(Group $group, Member $member): bool
    {
        if ($group->topic_post_authority === TopicPostAuthority::AdminsOnly) {
            return GroupMembership::isAdmin($group, $member);
        }

        return GroupMembership::isMember($group, $member);
    }

    /**
     * May the member edit or delete this topic? The author may, but only while still a member
     * (OpenPNE 3 isEditable checks membership first), and a group admin always may.
     */
    public static function canEditTopic(GroupTopic $topic, Member $member): bool
    {
        $group = $topic->group;

        if (! GroupMembership::isMember($group, $member)) {
            return false;
        }

        return $member->getKey() === $topic->member_id
            || GroupMembership::isAdmin($group, $member);
    }

    /** May the member comment? Any group member may (OpenPNE 3 isCreatableCommunityTopicComment). */
    public static function canComment(GroupTopic $topic, Member $member): bool
    {
        return GroupMembership::isMember($topic->group, $member);
    }

    /**
     * May the member delete this comment? Its author may (unless withdrawn), or anyone who may edit
     * the topic (OpenPNE 3 GroupTopicComment::isDeletable).
     */
    public static function canDeleteComment(GroupTopicComment $comment, Member $member): bool
    {
        if ($comment->member_id !== null && $member->getKey() === $comment->member_id) {
            return true;
        }

        return self::canEditTopic($comment->topic, $member);
    }
}
