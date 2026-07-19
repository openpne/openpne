<?php

namespace App\Features\Community\Actions;

use App\Features\Community\CommunityMembership;
use App\Features\Community\CommunityRole;
use App\Features\Community\Events\SubAdminAppointed;
use App\Features\Community\Exceptions\CommunityActionException;
use App\Features\Community\Exceptions\CommunityActionFailure;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/** Promote a plain member to sub-admin. See AcceptAdminTransfer for the community-row lock protocol. */
class AppointSubAdmin
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
            if ($role !== CommunityRole::Member) {
                throw new CommunityActionException(CommunityActionFailure::TargetNotPlainMember);
            }

            // A pending transfer nominee's role is frozen until the transfer resolves — OpenPNE 3
            // refused sub-admin nomination for an admin_confirm holder.
            if ((int) $locked->pending_admin_member_id === (int) $target->getKey()) {
                throw new CommunityActionException(CommunityActionFailure::TargetIsPendingAdmin);
            }

            CommunityMember::query()
                ->where('community_id', $locked->getKey())
                ->where('member_id', $target->getKey())
                ->update(['role' => CommunityRole::SubAdmin]);

            SubAdminAppointed::dispatch($locked, $actor, $target);
        });
    }
}
