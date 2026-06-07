<?php

namespace App\Features\CommunityTopic;

use App\Features\Community\CommunityMembership;
use App\Models\Community;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\Member;

/**
 * The single authorization chokepoint for the community topic board, porting OpenPNE 3's
 * opCommunityTopicAclBuilder + opIsCreatableCommunityTopicBehavior. Read/post gates depend on the
 * community's two access columns; edit/delete depend on authorship and role. Every decision flows
 * through CommunityMembership so it cannot drift from "what is this member to this community".
 */
class CommunityTopicAccess
{
    /**
     * May the member read this community's board (list + show)? MembersOnly requires membership;
     * Everyone admits any signed-in member (OpenPNE 3 public_flag).
     */
    public static function canViewBoard(Community $community, Member $member): bool
    {
        if ($community->topic_read_access === TopicReadAccess::MembersOnly) {
            return CommunityMembership::isMember($community, $member);
        }

        return true;
    }

    /** Read access for a single topic — the same gate as the board it belongs to. */
    public static function canViewTopic(CommunityTopic $topic, Member $member): bool
    {
        return self::canViewBoard($topic->community, $member);
    }

    /**
     * May the member post a topic? AdminsOnly requires admin; Members requires membership
     * (OpenPNE 3 topic_authority). Note this gates posting topics only — commenting is open to any
     * member regardless (see canComment).
     */
    public static function canPostTopic(Community $community, Member $member): bool
    {
        if ($community->topic_post_authority === TopicPostAuthority::AdminsOnly) {
            return CommunityMembership::isAdmin($community, $member);
        }

        return CommunityMembership::isMember($community, $member);
    }

    /**
     * May the member edit or delete this topic? The author may, but only while still a member
     * (OpenPNE 3 isEditable checks membership first), and a community admin always may.
     */
    public static function canEditTopic(CommunityTopic $topic, Member $member): bool
    {
        $community = $topic->community;

        if (! CommunityMembership::isMember($community, $member)) {
            return false;
        }

        return $member->getKey() === $topic->member_id
            || CommunityMembership::isAdmin($community, $member);
    }

    /** May the member comment? Any community member may (OpenPNE 3 isCreatableCommunityTopicComment). */
    public static function canComment(CommunityTopic $topic, Member $member): bool
    {
        return CommunityMembership::isMember($topic->community, $member);
    }

    /**
     * May the member delete this comment? Its author may (unless withdrawn), or anyone who may edit
     * the topic (OpenPNE 3 CommunityTopicComment::isDeletable).
     */
    public static function canDeleteComment(CommunityTopicComment $comment, Member $member): bool
    {
        if ($comment->member_id !== null && $member->getKey() === $comment->member_id) {
            return true;
        }

        return self::canEditTopic($comment->topic, $member);
    }
}
