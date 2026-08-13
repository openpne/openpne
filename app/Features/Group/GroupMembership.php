<?php

namespace App\Features\Group;

use App\Models\Group;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * The single read chokepoint for "what is this member to this group". group_members
 * holds confirmed members only and group_join_requests holds pending applicants, so these
 * helpers answer membership/role without any pending-filter for a caller to forget.
 */
class GroupMembership
{
    public static function roleOf(Group $group, Member $member): ?GroupRole
    {
        $value = DB::table('group_members')
            ->where('group_id', $group->getKey())
            ->where('member_id', $member->getKey())
            ->value('role');

        return $value === null ? null : GroupRole::from((int) $value);
    }

    public static function isMember(Group $group, Member $member): bool
    {
        return self::roleOf($group, $member) !== null;
    }

    public static function isPending(Group $group, Member $member): bool
    {
        return DB::table('group_join_requests')
            ->where('group_id', $group->getKey())
            ->where('member_id', $member->getKey())
            ->exists();
    }

    public static function isAdmin(Group $group, Member $member): bool
    {
        return self::roleOf($group, $member) === GroupRole::Admin;
    }

    public static function canManage(Group $group, Member $member): bool
    {
        return self::roleOf($group, $member)?->canManage() ?? false;
    }
}
