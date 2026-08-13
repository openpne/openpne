<?php

namespace App\Policies;

use App\Features\Group\GroupMembership;
use App\Models\Group;
use App\Models\Member;

class GroupPolicy
{
    /** Any authenticated member may view any group. */
    public function view(Member $viewer, Group $group): bool
    {
        return true;
    }

    /** Edit group settings (name, description, category, policy): admin or sub-admin. */
    public function update(Member $actor, Group $group): bool
    {
        return GroupMembership::canManage($group, $actor);
    }

    public function delete(Member $actor, Group $group): bool
    {
        return GroupMembership::isAdmin($group, $actor);
    }

    /** Approve/decline pending members: admin only. */
    public function manageMembers(Member $actor, Group $group): bool
    {
        return GroupMembership::isAdmin($group, $actor);
    }

    /** View the member-management screen and drop plain members: admin or sub-admin. */
    public function moderateMembers(Member $actor, Group $group): bool
    {
        return GroupMembership::canManage($group, $actor);
    }
}
