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

/** See docs/internals/group-boards.md, "The group row is the lock". */
class AcceptAdminTransfer
{
    public function __invoke(Member $actor, Group $group): void
    {
        // The NotMember arm clears the dangling pending seat, so its failure is returned and thrown
        // after the commit rather than inside the transaction, which would roll that clear back.
        $failure = DB::transaction(function () use ($actor, $group): ?GroupActionFailure {
            $locked = Group::whereKey($group->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->pending_admin_member_id !== (int) $actor->getKey()) {
                return GroupActionFailure::NoTransferPending;
            }

            if (! GroupMembership::isMember($locked, $actor)) {
                $locked->pending_admin_member_id = null;
                $locked->save();

                return GroupActionFailure::NotMember;
            }

            GroupMember::query()
                ->where('group_id', $locked->getKey())
                ->where('role', GroupRole::Admin->value)
                ->update(['role' => GroupRole::Member]);

            GroupMember::query()
                ->where('group_id', $locked->getKey())
                ->where('member_id', $actor->getKey())
                ->update(['role' => GroupRole::Admin]);

            $locked->pending_admin_member_id = null;
            $locked->save();

            return null;
        });

        ViewerRelations::flush();

        if ($failure !== null) {
            throw new GroupActionException($failure);
        }
    }
}
