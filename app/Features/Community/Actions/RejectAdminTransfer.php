<?php

namespace App\Features\Community\Actions;

use App\Features\Community\Exceptions\CommunityActionException;
use App\Features\Community\Exceptions\CommunityActionFailure;
use App\Models\Community;
use App\Models\Member;

/**
 * The nominee declines a pending admin transfer. A single conditional UPDATE is its own
 * compare-and-set, so no lock is needed: 0 rows changed means the pending seat is not the actor's.
 */
class RejectAdminTransfer
{
    public function __invoke(Member $actor, Community $community): void
    {
        $cleared = Community::whereKey($community->getKey())
            ->where('pending_admin_member_id', $actor->getKey())
            ->update(['pending_admin_member_id' => null]);

        if ($cleared === 0) {
            throw new CommunityActionException(CommunityActionFailure::NoTransferPending);
        }
    }
}
