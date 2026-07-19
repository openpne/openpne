<?php

namespace App\Features\Community\Actions;

use App\Features\Community\CommunityMembership;
use App\Features\Community\CommunityRole;
use App\Features\Community\Exceptions\CommunityActionException;
use App\Features\Community\Exceptions\CommunityActionFailure;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/** Remove a plain member from a community. See AcceptAdminTransfer for the community-row lock protocol. */
class DropMember
{
    public function __invoke(Member $actor, Community $community, Member $target): void
    {
        DB::transaction(function () use ($actor, $community, $target): void {
            $locked = Community::whereKey($community->getKey())->lockForUpdate()->firstOrFail();

            if (! CommunityMembership::canManage($locked, $actor)) {
                throw new CommunityActionException(CommunityActionFailure::NotManager);
            }

            $role = CommunityMembership::roleOf($locked, $target);
            if ($role === null) {
                throw new CommunityActionException(CommunityActionFailure::NotMember);
            }
            // Only plain members are droppable — this also excludes the actor, always a manager.
            if ($role !== CommunityRole::Member) {
                throw new CommunityActionException(CommunityActionFailure::TargetNotPlainMember);
            }

            CommunityMember::query()
                ->where('community_id', $locked->getKey())
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
