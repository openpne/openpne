<?php

namespace App\Features\Group\Actions;

use App\Features\Group\Exceptions\GroupActionException;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Features\Group\GroupMembership;
use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Support\ViewerRelations;
use Illuminate\Support\Facades\DB;

/** Demote a sub-admin back to plain member. See AcceptAdminTransfer for the group-row lock protocol. */
class DemoteSubAdmin
{
    public function __invoke(Member $actor, Group $group, Member $target): void
    {
        DB::transaction(function () use ($actor, $group, $target): void {
            $locked = Group::whereKey($group->getKey())->lockForUpdate()->firstOrFail();

            if (! GroupMembership::isAdmin($locked, $actor)) {
                throw new GroupActionException(GroupActionFailure::NotAdmin);
            }

            $role = GroupMembership::roleOf($locked, $target);
            if ($role === null) {
                throw new GroupActionException(GroupActionFailure::NotMember);
            }
            if ($role !== GroupRole::SubAdmin) {
                throw new GroupActionException(GroupActionFailure::NotSubAdmin);
            }

            // pending is left untouched: a nominee who is also a sub-admin may be demoted (the
            // transfer stands — nominees need only be non-admin members).
            GroupMember::query()
                ->where('group_id', $locked->getKey())
                ->where('member_id', $target->getKey())
                ->update(['role' => GroupRole::Member]);
        });

        ViewerRelations::flush();
    }
}
