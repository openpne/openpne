<?php

namespace App\Features\Group\Actions;

use App\Features\Group\Events\GroupJoined;
use App\Features\Group\Exceptions\GroupActionException;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Features\Group\GroupMembership;
use App\Features\Group\GroupRole;
use App\Features\GroupTalk\TalkReadCursor;
use App\Models\Group;
use App\Models\Member;
use App\Support\ViewerRelations;
use Illuminate\Support\Facades\DB;

class ApproveMember
{
    public function __invoke(Member $actor, Group $group, Member $applicant): void
    {
        // The admin check re-runs under the group-row lock, so a transfer accepted after page load
        // cannot let an ex-admin approve (docs/internals/group-boards.md, "The group row is the lock").
        DB::transaction(function () use ($actor, $group, $applicant) {
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

            $locked->members()->forceCreate([
                'member_id' => $applicant->getKey(),
                'role' => GroupRole::Member,
                // Read up to the moment of approval, not the moment of applying (TalkReadCursor).
                ...TalkReadCursor::snapshot((int) $locked->getKey()),
            ]);

            GroupJoined::dispatch($locked, $applicant);
        });

        ViewerRelations::flush();
    }
}
