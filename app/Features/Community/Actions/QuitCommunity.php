<?php

namespace App\Features\Community\Actions;

use App\Features\Community\CommunityRole;
use App\Features\Community\Exceptions\CommunityActionException;
use App\Features\Community\Exceptions\CommunityActionFailure;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;

class QuitCommunity
{
    public function __invoke(Member $member, Community $community): void
    {
        $membership = CommunityMember::query()
            ->where('community_id', $community->getKey())
            ->where('member_id', $member->getKey())
            ->first();

        if ($membership === null) {
            throw new CommunityActionException(CommunityActionFailure::NotMember);
        }

        // One admin per community in Phase A (transfer is deferred), so the admin must hand off
        // before leaving — OpenPNE 3's "the admin cannot quit".
        if ($membership->role === CommunityRole::Admin) {
            throw new CommunityActionException(CommunityActionFailure::AdminCannotQuit);
        }

        $membership->delete();
    }
}
