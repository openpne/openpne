<?php

namespace App\Features\Group\Actions;

use App\Features\Group\Events\SubAdminAppointed;
use App\Features\Group\Exceptions\GroupActionException;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Features\Group\GroupMembership;
use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Support\ViewerRelations;
use Illuminate\Support\Facades\DB;

/** See docs/internals/group-boards.md, "The group row is the lock". */
class AppointSubAdmin
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
            if ($role !== GroupRole::Member) {
                throw new GroupActionException(GroupActionFailure::TargetNotPlainMember);
            }

            // A pending transfer nominee's role is frozen until the transfer resolves — OpenPNE 3
            // refused sub-admin nomination for an admin_confirm holder.
            if ((int) $locked->pending_admin_member_id === (int) $target->getKey()) {
                throw new GroupActionException(GroupActionFailure::TargetIsPendingAdmin);
            }

            GroupMember::query()
                ->where('group_id', $locked->getKey())
                ->where('member_id', $target->getKey())
                ->update(['role' => GroupRole::SubAdmin]);

            SubAdminAppointed::dispatch($locked, $actor, $target);
        });

        ViewerRelations::flush();
    }
}
