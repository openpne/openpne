<?php

namespace App\Features\GroupEvent;

use App\Features\Group\GroupMembership;
use App\Features\GroupTopic\TopicPostAuthority;
use App\Features\GroupTopic\TopicReadAccess;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\Member;

/**
 * OpenPNE 3's opCommunityEventAclBuilder extends opCommunityTopicAclBuilder with no overrides, so
 * events share the topic ACL semantics and the group's same two access columns. Every decision
 * flows through GroupMembership.
 */
class GroupEventAccess
{
    public static function canViewBoard(Group $group, Member $member): bool
    {
        if ($group->topic_read_access === TopicReadAccess::MembersOnly) {
            return GroupMembership::isMember($group, $member);
        }

        return true;
    }

    public static function canViewEvent(GroupEvent $event, Member $member): bool
    {
        return self::canViewBoard($event->group, $member);
    }

    /** The board's topic_post_authority, shared with topics (OpenPNE 3 topic_authority). */
    public static function canPostEvent(Group $group, Member $member): bool
    {
        if ($group->topic_post_authority === TopicPostAuthority::AdminsOnly) {
            return GroupMembership::isAdmin($group, $member);
        }

        return GroupMembership::isMember($group, $member);
    }

    /** The author may, but only while still a member (OpenPNE 3 isEditable checks membership first). */
    public static function canEditEvent(GroupEvent $event, Member $member): bool
    {
        $group = $event->group;

        if (! GroupMembership::isMember($group, $member)) {
            return false;
        }

        return $member->getKey() === $event->member_id
            || GroupMembership::isAdmin($group, $member);
    }

    public static function canComment(GroupEvent $event, Member $member): bool
    {
        return GroupMembership::isMember($event->group, $member);
    }

    /**
     * Same gate as commenting: OpenPNE 3 routes participation through the comment-create action,
     * which requires membership. Time and capacity limits are enforced when the toggle runs.
     */
    public static function canParticipate(GroupEvent $event, Member $member): bool
    {
        return GroupMembership::isMember($event->group, $member);
    }

    /** Its author may unless withdrawn, or anyone who may edit the event. */
    public static function canDeleteComment(GroupEventComment $comment, Member $member): bool
    {
        if ($comment->member_id !== null && $member->getKey() === $comment->member_id) {
            return true;
        }

        return self::canEditEvent($comment->event, $member);
    }
}
