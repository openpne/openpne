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
 * The single authorization chokepoint for community events. OpenPNE 3's opCommunityEventAclBuilder
 * extends opCommunityTopicAclBuilder with no overrides, so events share the topic ACL semantics and
 * the same two community access columns (topic_read_access / topic_post_authority) — OpenPNE 3 reads
 * one public_flag / topic_authority config for both. Every decision flows through GroupMembership.
 */
class GroupEventAccess
{
    /** May the member read this community's events (list + show + member list)? */
    public static function canViewBoard(Group $group, Member $member): bool
    {
        if ($group->topic_read_access === TopicReadAccess::MembersOnly) {
            return GroupMembership::isMember($group, $member);
        }

        return true;
    }

    /** Read access for a single event — the same gate as the community it belongs to. */
    public static function canViewEvent(GroupEvent $event, Member $member): bool
    {
        return self::canViewBoard($event->group, $member);
    }

    /**
     * May the member create an event? AdminsOnly requires admin; Members requires membership
     * (OpenPNE 3 topic_authority, shared with topics).
     */
    public static function canPostEvent(Group $group, Member $member): bool
    {
        if ($group->topic_post_authority === TopicPostAuthority::AdminsOnly) {
            return GroupMembership::isAdmin($group, $member);
        }

        return GroupMembership::isMember($group, $member);
    }

    /**
     * May the member edit or delete this event? The author may, but only while still a member
     * (OpenPNE 3 isEditable checks membership first), and a community admin always may.
     */
    public static function canEditEvent(GroupEvent $event, Member $member): bool
    {
        $group = $event->group;

        if (! GroupMembership::isMember($group, $member)) {
            return false;
        }

        return $member->getKey() === $event->member_id
            || GroupMembership::isAdmin($group, $member);
    }

    /** May the member comment? Any community member may. */
    public static function canComment(GroupEvent $event, Member $member): bool
    {
        return GroupMembership::isMember($event->group, $member);
    }

    /**
     * May the member RSVP (join/cancel)? Same gate as commenting — OpenPNE 3 routes participation
     * through the comment-create action, which requires membership. Time/capacity limits are
     * enforced separately when the toggle runs (ToggleParticipation).
     */
    public static function canParticipate(GroupEvent $event, Member $member): bool
    {
        return GroupMembership::isMember($event->group, $member);
    }

    /**
     * May the member delete this comment? Its author may (unless withdrawn), or anyone who may edit
     * the event.
     */
    public static function canDeleteComment(GroupEventComment $comment, Member $member): bool
    {
        if ($comment->member_id !== null && $member->getKey() === $comment->member_id) {
            return true;
        }

        return self::canEditEvent($comment->event, $member);
    }
}
