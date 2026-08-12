<?php

namespace App\Features\Timeline;

use App\Features\Community\CommunityMembership;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Models\Community;
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
    public static function canViewTimeline(Community $community, Member $member): bool
    {
        if ($community->topic_read_access === TopicReadAccess::MembersOnly) {
            return CommunityMembership::isMember($community, $member);
        }

        return true;
    }

    /**
     * May the member post or reply here? Membership, whatever the read gate — an Everyone community
     * is readable by any member but writable only by its own (OpenPNE 3 checkCommunityMember).
     */
    public static function canPost(Community $community, Member $member): bool
    {
        return CommunityMembership::isMember($community, $member);
    }
}
