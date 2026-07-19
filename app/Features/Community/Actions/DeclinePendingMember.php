<?php

namespace App\Features\Community\Actions;

use App\Features\Community\CommunityMembership;
use App\Features\Community\Exceptions\CommunityActionException;
use App\Features\Community\Exceptions\CommunityActionFailure;
use App\Models\Community;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class DeclinePendingMember
{
    public function __invoke(Member $actor, Community $community, Member $applicant): void
    {
        // The admin check re-runs under the community-row lock (see AcceptAdminTransfer) so a transfer
        // accepted after page load can't let an ex-admin decline.
        DB::transaction(function () use ($actor, $community, $applicant): void {
            $locked = Community::whereKey($community->getKey())->lockForUpdate()->firstOrFail();

            if (! CommunityMembership::isAdmin($locked, $actor)) {
                throw new CommunityActionException(CommunityActionFailure::NotAdmin);
            }

            $deleted = DB::table('community_join_requests')
                ->where('community_id', $locked->getKey())
                ->where('member_id', $applicant->getKey())
                ->delete();

            if ($deleted === 0) {
                throw new CommunityActionException(CommunityActionFailure::NotPending);
            }
        });
    }
}
