<?php

namespace App\Features\Group\Actions;

use App\Features\Group\Exceptions\GroupActionException;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Models\Group;
use App\Models\Member;

/**
 * A single conditional UPDATE is its own compare-and-set, so this takes no group-row lock: 0 rows
 * changed means the pending seat is not the actor's.
 */
class RejectAdminTransfer
{
    public function __invoke(Member $actor, Group $group): void
    {
        $cleared = Group::whereKey($group->getKey())
            ->where('pending_admin_member_id', $actor->getKey())
            ->update(['pending_admin_member_id' => null]);

        if ($cleared === 0) {
            throw new GroupActionException(GroupActionFailure::NoTransferPending);
        }
    }
}
