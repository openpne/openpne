<?php

namespace App\Features\Group\Actions;

use App\Features\Group\Exceptions\GroupActionException;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Models\Group;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * QuitGroup cannot stand in for this: it deletes a confirmed membership row, and an applicant has
 * none. The lock is the one ApproveMember and DeclinePendingMember take, so a cancel racing an
 * approval resolves one way or the other rather than leaving both a membership and a request.
 */
class CancelGroupJoinRequest
{
    public function __invoke(Member $member, Group $group): void
    {
        DB::transaction(function () use ($member, $group): void {
            $locked = Group::whereKey($group->getKey())->lockForUpdate()->firstOrFail();

            $deleted = DB::table('group_join_requests')
                ->where('group_id', $locked->getKey())
                ->where('member_id', $member->getKey())
                ->delete();

            if ($deleted === 0) {
                throw new GroupActionException(GroupActionFailure::NotPending);
            }
        });
    }
}
