<?php

namespace App\Features\Community\Actions;

use App\Features\Community\CommunityMembership;
use App\Features\Community\CommunityRole;
use App\Features\Community\Events\AdminTransferRequested;
use App\Features\Community\Exceptions\CommunityActionException;
use App\Features\Community\Exceptions\CommunityActionFailure;
use App\Models\Community;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/** Nominate a member to take over the admin seat. See AcceptAdminTransfer for the community-row lock protocol. */
class RequestAdminTransfer
{
    public function __invoke(Member $actor, Community $community, Member $nominee): void
    {
        DB::transaction(function () use ($actor, $community, $nominee): void {
            $locked = Community::whereKey($community->getKey())->lockForUpdate()->firstOrFail();

            if (! CommunityMembership::isAdmin($locked, $actor)) {
                throw new CommunityActionException(CommunityActionFailure::NotAdmin);
            }

            // A sub-admin nominee is allowed; only the current admin (self) is refused.
            $role = CommunityMembership::roleOf($locked, $nominee);
            if ($role === null) {
                throw new CommunityActionException(CommunityActionFailure::NotMember);
            }
            if ($role === CommunityRole::Admin) {
                throw new CommunityActionException(CommunityActionFailure::TargetNotPlainMember);
            }

            if ((int) $locked->pending_admin_member_id === (int) $nominee->getKey()) {
                throw new CommunityActionException(CommunityActionFailure::TransferAlreadyRequested);
            }

            // A new request silently replaces a different pending nominee (OpenPNE 3 semantics). The
            // replaced nominee's earlier feed row stays as history; the banner keys off live pending
            // state, so it disappears for them the moment this write commits.
            $locked->pending_admin_member_id = $nominee->getKey();
            $locked->save();

            AdminTransferRequested::dispatch($locked, $actor, $nominee);
        });
    }
}
