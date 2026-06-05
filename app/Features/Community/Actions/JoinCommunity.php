<?php

namespace App\Features\Community\Actions;

use App\Features\Community\CommunityMembership;
use App\Features\Community\CommunityRole;
use App\Features\Community\Events\CommunityJoined;
use App\Features\Community\Events\CommunityJoinRequested;
use App\Features\Community\Exceptions\CommunityActionException;
use App\Features\Community\Exceptions\CommunityActionFailure;
use App\Features\Community\JoinPolicy;
use App\Models\Community;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class JoinCommunity
{
    public function __invoke(Member $member, Community $community): void
    {
        if (CommunityMembership::isMember($community, $member)) {
            throw new CommunityActionException(CommunityActionFailure::AlreadyMember);
        }

        if ($community->register_policy === JoinPolicy::Approval) {
            if (CommunityMembership::isPending($community, $member)) {
                throw new CommunityActionException(CommunityActionFailure::AlreadyRequested);
            }

            DB::transaction(function () use ($member, $community) {
                DB::table('community_join_requests')->insert([
                    'community_id' => $community->getKey(),
                    'member_id' => $member->getKey(),
                    'created_at' => now(),
                ]);

                CommunityJoinRequested::dispatch($community, $member);
            });

            return;
        }

        DB::transaction(function () use ($member, $community) {
            $community->members()->create([
                'member_id' => $member->getKey(),
                'role' => CommunityRole::Member,
            ]);

            CommunityJoined::dispatch($community, $member);
        });
    }
}
