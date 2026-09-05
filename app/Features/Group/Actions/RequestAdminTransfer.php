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

/** See docs/internals/group-boards.md, "The group row is the lock". */
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

            // A replaced nominee's earlier feed row stays as history; their banner keys off live
            // pending state rather than that row.
            $locked->pending_admin_member_id = $nominee->getKey();
            $locked->save();

            AdminTransferRequested::dispatch($locked, $actor, $nominee);
        });
    }
}
