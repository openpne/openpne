<?php

namespace App\Features\Community;

use App\Models\Community;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * The single read chokepoint for "what is this member to this community". community_members
 * holds confirmed members only and community_join_requests holds pending applicants, so these
 * helpers answer membership/role without any pending-filter for a caller to forget.
 */
class CommunityMembership
{
    public static function roleOf(Community $community, Member $member): ?CommunityRole
    {
        $value = DB::table('community_members')
            ->where('community_id', $community->getKey())
            ->where('member_id', $member->getKey())
            ->value('role');

        return $value === null ? null : CommunityRole::from((int) $value);
    }

    public static function isMember(Community $community, Member $member): bool
    {
        return self::roleOf($community, $member) !== null;
    }

    public static function isPending(Community $community, Member $member): bool
    {
        return DB::table('community_join_requests')
            ->where('community_id', $community->getKey())
            ->where('member_id', $member->getKey())
            ->exists();
    }

    public static function isAdmin(Community $community, Member $member): bool
    {
        return self::roleOf($community, $member) === CommunityRole::Admin;
    }

    public static function canManage(Community $community, Member $member): bool
    {
        return self::roleOf($community, $member)?->canManage() ?? false;
    }
}
