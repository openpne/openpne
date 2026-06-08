<?php

namespace App\Features\CommunityEvent;

use App\Features\Community\CommunityMembership;
use App\Features\CommunityTopic\TopicPostAuthority;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\Member;

/**
 * The single authorization chokepoint for community events. OpenPNE 3's opCommunityEventAclBuilder
 * extends opCommunityTopicAclBuilder with no overrides, so events share the topic ACL semantics and
 * the same two community access columns (topic_read_access / topic_post_authority) — OpenPNE 3 reads
 * one public_flag / topic_authority config for both. Every decision flows through CommunityMembership.
 */
class CommunityEventAccess
{
    /** May the member read this community's events (list + show + member list)? */
    public static function canViewBoard(Community $community, Member $member): bool
    {
        if ($community->topic_read_access === TopicReadAccess::MembersOnly) {
            return CommunityMembership::isMember($community, $member);
        }

        return true;
    }

    /** Read access for a single event — the same gate as the community it belongs to. */
    public static function canViewEvent(CommunityEvent $event, Member $member): bool
    {
        return self::canViewBoard($event->community, $member);
    }

    /**
     * May the member create an event? AdminsOnly requires admin; Members requires membership
     * (OpenPNE 3 topic_authority, shared with topics).
     */
    public static function canPostEvent(Community $community, Member $member): bool
    {
        if ($community->topic_post_authority === TopicPostAuthority::AdminsOnly) {
            return CommunityMembership::isAdmin($community, $member);
        }

        return CommunityMembership::isMember($community, $member);
    }

    /**
     * May the member edit or delete this event? The author may, but only while still a member
     * (OpenPNE 3 isEditable checks membership first), and a community admin always may.
     */
    public static function canEditEvent(CommunityEvent $event, Member $member): bool
    {
        $community = $event->community;

        if (! CommunityMembership::isMember($community, $member)) {
            return false;
        }

        return $member->getKey() === $event->member_id
            || CommunityMembership::isAdmin($community, $member);
    }

    /** May the member comment? Any community member may (OpenPNE 3 isCreatableCommunityEventComment). */
    public static function canComment(CommunityEvent $event, Member $member): bool
    {
        return CommunityMembership::isMember($event->community, $member);
    }

    /**
     * May the member RSVP (join/cancel)? Same gate as commenting — OpenPNE 3 routes participation
     * through the comment-create action, which requires membership. Time/capacity limits are
     * enforced separately when the toggle runs (ToggleParticipation).
     */
    public static function canParticipate(CommunityEvent $event, Member $member): bool
    {
        return CommunityMembership::isMember($event->community, $member);
    }

    /**
     * May the member delete this comment? Its author may (unless withdrawn), or anyone who may edit
     * the event (OpenPNE 3 CommunityEventComment::isDeletable).
     */
    public static function canDeleteComment(CommunityEventComment $comment, Member $member): bool
    {
        if ($comment->member_id !== null && $member->getKey() === $comment->member_id) {
            return true;
        }

        return self::canEditEvent($comment->event, $member);
    }
}
