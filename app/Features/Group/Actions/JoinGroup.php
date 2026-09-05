<?php

namespace App\Features\Group\Actions;

use App\Features\Group\Events\GroupJoined;
use App\Features\Group\Events\GroupJoinRequested;
use App\Features\Group\Exceptions\GroupActionException;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Features\Group\GroupMembership;
use App\Features\Group\GroupRole;
use App\Features\Group\JoinPolicy;
use App\Features\GroupTalk\TalkReadCursor;
use App\Models\Group;
use App\Models\Member;
use App\Support\ViewerRelations;
use Illuminate\Support\Facades\DB;

class JoinGroup
{
    public function __invoke(Member $member, Group $group): void
    {
        if (GroupMembership::isMember($group, $member)) {
            throw new GroupActionException(GroupActionFailure::AlreadyMember);
        }

        // Guarded here rather than only in the Approval branch: a policy flipped Approval→Open
        // between applying and re-joining must not leave both a membership and a stale request.
        if (GroupMembership::isPending($group, $member)) {
            throw new GroupActionException(GroupActionFailure::AlreadyRequested);
        }

        if ($group->register_policy === JoinPolicy::Approval) {
            DB::transaction(function () use ($member, $group) {
                DB::table('group_join_requests')->insert([
                    'group_id' => $group->getKey(),
                    'member_id' => $member->getKey(),
                    'created_at' => now(),
                ]);

                GroupJoinRequested::dispatch($group, $member);
            });

            return;
        }

        DB::transaction(function () use ($member, $group) {
            $group->members()->forceCreate([
                'member_id' => $member->getKey(),
                'role' => GroupRole::Member,
                // Everything said before joining counts as read (TalkReadCursor).
                ...TalkReadCursor::snapshot((int) $group->getKey()),
            ]);

            GroupJoined::dispatch($group, $member);
        });

        ViewerRelations::flush();
    }
}
