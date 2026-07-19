<?php

namespace App\Features\Community\Actions;

use App\Features\Community\CommunityMembership;
use App\Features\Community\CommunityRole;
use App\Features\Community\Events\CommunityJoined;
use App\Features\Community\Exceptions\CommunityActionException;
use App\Features\Community\Exceptions\CommunityActionFailure;
use App\Models\Community;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class ApproveMember
{
    public function __invoke(Member $actor, Community $community, Member $applicant): void
    {
        // Move the pending request into a confirmed membership atomically (cf. AcceptFriendRequest).
        // The admin check re-runs under the community-row lock (see AcceptAdminTransfer): a transfer
        // accepted after page load could have demoted this ex-admin.
        DB::transaction(function () use ($actor, $community, $applicant) {
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

            $locked->members()->create([
                'member_id' => $applicant->getKey(),
                'role' => CommunityRole::Member,
            ]);

            CommunityJoined::dispatch($locked, $applicant);
        });
    }
}
