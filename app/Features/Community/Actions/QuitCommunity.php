<?php

namespace App\Features\Community\Actions;

use App\Features\Community\CommunityRole;
use App\Features\Community\Exceptions\CommunityActionException;
use App\Features\Community\Exceptions\CommunityActionFailure;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/** A member leaves a community. See AcceptAdminTransfer for the community-row lock protocol. */
class QuitCommunity
{
    public function __invoke(Member $member, Community $community): void
    {
        DB::transaction(function () use ($member, $community): void {
            $locked = Community::whereKey($community->getKey())->lockForUpdate()->firstOrFail();

            // Re-read the role under the lock so AdminCannotQuit stays correct even if a concurrent
            // AcceptAdminTransfer just promoted this member to admin between page load and here.
            $membership = CommunityMember::query()
                ->where('community_id', $locked->getKey())
                ->where('member_id', $member->getKey())
                ->first();

            if ($membership === null) {
                throw new CommunityActionException(CommunityActionFailure::NotMember);
            }

            // One admin per community, so the admin must hand off before leaving —
            // OpenPNE 3's "the admin cannot quit".
            if ($membership->role === CommunityRole::Admin) {
                throw new CommunityActionException(CommunityActionFailure::AdminCannotQuit);
            }

            $membership->delete();

            // A leaving nominee cancels the pending transfer.
            if ((int) $locked->pending_admin_member_id === (int) $member->getKey()) {
                $locked->pending_admin_member_id = null;
                $locked->save();
            }
        });
    }
}
