<?php

namespace App\Features\Group;

use App\Models\Group;
use App\Models\Member;
use App\Support\ViewerRelations;
use Illuminate\Support\Facades\DB;

/**
 * group_members holds confirmed members only and group_join_requests the pending applicants, so
 * these helpers need no pending-filter for a caller to forget.
 */
class GroupMembership
{
    /**
     * A page that read this member's groups in bulk has already answered this pair
     * (ViewerRelations) — asked separately from the answer because no role is one of the answers.
     */
    public static function roleOf(Group $group, Member $member): ?GroupRole
    {
        $relations = app(ViewerRelations::class);

        if ($relations->knowsRole($group, $member)) {
            return $relations->roleIn($group, $member);
        }

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
