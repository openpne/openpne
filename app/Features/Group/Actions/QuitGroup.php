<?php

namespace App\Features\Group\Actions;

use App\Features\Group\Exceptions\GroupActionException;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Support\ViewerRelations;
use Illuminate\Support\Facades\DB;

/** A member leaves a group. See AcceptAdminTransfer for the group-row lock protocol. */
class QuitGroup
{
    public function __invoke(Member $member, Group $group): void
    {
        DB::transaction(function () use ($member, $group): void {
            $locked = Group::whereKey($group->getKey())->lockForUpdate()->firstOrFail();

            // Re-read the role under the lock so AdminCannotQuit stays correct even if a concurrent
            // AcceptAdminTransfer just promoted this member to admin between page load and here.
            $membership = GroupMember::query()
                ->where('group_id', $locked->getKey())
                ->where('member_id', $member->getKey())
                ->first();

            if ($membership === null) {
                throw new GroupActionException(GroupActionFailure::NotMember);
            }

            // One admin per group, so the admin must hand off before leaving —
            // OpenPNE 3's "the admin cannot quit".
            if ($membership->role === GroupRole::Admin) {
                throw new GroupActionException(GroupActionFailure::AdminCannotQuit);
            }

            $membership->delete();

            // A leaving nominee cancels the pending transfer.
            if ((int) $locked->pending_admin_member_id === (int) $member->getKey()) {
                $locked->pending_admin_member_id = null;
                $locked->save();
            }
        });

        ViewerRelations::flush();
    }
}
