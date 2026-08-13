<?php

namespace App\Features\Group\Actions;

use App\Features\Group\GroupMembership;
use App\Features\Group\GroupRole;
use App\Features\Group\Exceptions\GroupActionException;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/** Remove a plain member from a group. See AcceptAdminTransfer for the group-row lock protocol. */
class DropMember
{
    public function __invoke(Member $actor, Group $group, Member $target): void
    {
        DB::transaction(function () use ($actor, $group, $target): void {
            $locked = Group::whereKey($group->getKey())->lockForUpdate()->firstOrFail();

            if (! GroupMembership::canManage($locked, $actor)) {
                throw new GroupActionException(GroupActionFailure::NotManager);
            }

            $role = GroupMembership::roleOf($locked, $target);
            if ($role === null) {
                throw new GroupActionException(GroupActionFailure::NotMember);
            }
            // Only plain members are droppable — this also excludes the actor, always a manager.
            if ($role !== GroupRole::Member) {
                throw new GroupActionException(GroupActionFailure::TargetNotPlainMember);
            }

            GroupMember::query()
                ->where('group_id', $locked->getKey())
                ->where('member_id', $target->getKey())
                ->delete();

            // Dropping the pending nominee cancels the transfer.
            if ((int) $locked->pending_admin_member_id === (int) $target->getKey()) {
                $locked->pending_admin_member_id = null;
                $locked->save();
            }
        });
    }
}
