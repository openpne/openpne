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
        if (! CommunityMembership::isAdmin($community, $actor)) {
            throw new CommunityActionException(CommunityActionFailure::NotAdmin);
        }

        // Move the pending request into a confirmed membership atomically (cf. AcceptFriendRequest).
        DB::transaction(function () use ($community, $applicant) {
            $deleted = DB::table('community_join_requests')
                ->where('community_id', $community->getKey())
                ->where('member_id', $applicant->getKey())
                ->delete();

            if ($deleted === 0) {
                throw new CommunityActionException(CommunityActionFailure::NotPending);
            }

            $community->members()->create([
                'member_id' => $applicant->getKey(),
                'role' => CommunityRole::Member,
            ]);

            CommunityJoined::dispatch($community, $applicant);
        });
    }
}
