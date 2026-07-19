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

/** Demote a sub-admin back to plain member. See AcceptAdminTransfer for the community-row lock protocol. */
class DemoteSubAdmin
{
    public function __invoke(Member $actor, Community $community, Member $target): void
    {
        DB::transaction(function () use ($actor, $community, $target): void {
            $locked = Community::whereKey($community->getKey())->lockForUpdate()->firstOrFail();

            if (! CommunityMembership::isAdmin($locked, $actor)) {
                throw new CommunityActionException(CommunityActionFailure::NotAdmin);
            }

            $role = CommunityMembership::roleOf($locked, $target);
            if ($role === null) {
                throw new CommunityActionException(CommunityActionFailure::NotMember);
            }
            if ($role !== CommunityRole::SubAdmin) {
                throw new CommunityActionException(CommunityActionFailure::NotSubAdmin);
            }

            // pending is left untouched: a nominee who is also a sub-admin may be demoted (the
            // transfer stands — nominees need only be non-admin members).
            CommunityMember::query()
                ->where('community_id', $locked->getKey())
                ->where('member_id', $target->getKey())
                ->update(['role' => CommunityRole::Member]);
        });
    }
}
