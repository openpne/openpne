<?php

namespace App\Features\Group\Actions;

use App\Features\Group\Exceptions\GroupActionException;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Features\Group\GroupMembership;
use App\Models\Group;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class DeclinePendingMember
{
    public function __invoke(Member $actor, Group $group, Member $applicant): void
    {
        // The admin check re-runs under the group-row lock, so a transfer accepted after page load
        // cannot let an ex-admin decline (docs/internals/group-boards.md, "The group row is the lock").
        DB::transaction(function () use ($actor, $group, $applicant): void {
            $locked = Group::whereKey($group->getKey())->lockForUpdate()->firstOrFail();

            if (! GroupMembership::isAdmin($locked, $actor)) {
                throw new GroupActionException(GroupActionFailure::NotAdmin);
            }

            $deleted = DB::table('group_join_requests')
                ->where('group_id', $locked->getKey())
                ->where('member_id', $applicant->getKey())
                ->delete();

            if ($deleted === 0) {
                throw new GroupActionException(GroupActionFailure::NotPending);
            }
        });
    }
}
