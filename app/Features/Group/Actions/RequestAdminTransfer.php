<?php

namespace App\Features\Group\Actions;

use App\Features\Group\Events\AdminTransferRequested;
use App\Features\Group\Exceptions\GroupActionException;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Features\Group\GroupMembership;
use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/** Nominate a member to take over the admin seat. See AcceptAdminTransfer for the group-row lock protocol. */
class RequestAdminTransfer
{
    public function __invoke(Member $actor, Group $group, Member $nominee): void
    {
        DB::transaction(function () use ($actor, $group, $nominee): void {
            $locked = Group::whereKey($group->getKey())->lockForUpdate()->firstOrFail();

            if (! GroupMembership::isAdmin($locked, $actor)) {
                throw new GroupActionException(GroupActionFailure::NotAdmin);
            }

            // A sub-admin nominee is allowed; only the current admin (self) is refused.
            $role = GroupMembership::roleOf($locked, $nominee);
            if ($role === null) {
                throw new GroupActionException(GroupActionFailure::NotMember);
            }
            if ($role === GroupRole::Admin) {
                throw new GroupActionException(GroupActionFailure::TargetNotPlainMember);
            }

            if ((int) $locked->pending_admin_member_id === (int) $nominee->getKey()) {
                throw new GroupActionException(GroupActionFailure::TransferAlreadyRequested);
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
