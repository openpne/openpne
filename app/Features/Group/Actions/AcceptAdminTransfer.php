<?php

namespace App\Features\Group\Actions;

use App\Features\Group\Exceptions\GroupActionException;
use App\Features\Group\Exceptions\GroupActionFailure;
use App\Features\Group\GroupMembership;
use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Support\ViewerRelations;
use Illuminate\Support\Facades\DB;

/**
 * Lock protocol for group role / pending_admin_member_id writes:
 *
 * Every action that changes a member's role or the pending-admin nominee opens with
 * `DB::transaction` and re-reads the group row `->lockForUpdate()`, then runs ALL of its role
 * guards against that locked re-read — never against a role snapshot taken before the lock. The
 * group row is the single serialization point, so concurrent appoint/demote/drop/transfer/quit
 * and admin withdrawal can't interleave: only one holds the lock at a time, and each sees the others'
 * committed effects. This is what keeps "exactly one admin per group" true under races (e.g. two
 * members accepting a transfer, or a transfer accepted while the old admin withdraws).
 *
 * The nominee promotes from Member or Sub-admin; the incumbent admin is demoted to Member and the
 * pending seat is cleared, all under the lock.
 *
 * Accepted edges (OpenPNE 3 behaves the same):
 *  - A transfer pending across the old admin's withdrawal survives it: WithdrawMember auto-promotes
 *    the longest-tenured member, and a later accept then demotes that successor. The nominee wins.
 *  - DeleteGroup has a TOCTOU: an ex-admin's in-flight delete can complete after a transfer, since
 *    its irreversible byte purge runs outside this lock. The harm equals a delete done just before the
 *    transfer, so it is accepted rather than folding the purge into the lock.
 */
class AcceptAdminTransfer
{
    public function __invoke(Member $actor, Group $group): void
    {
        // The defensive NotMember arm must clear the dangling pending and keep that clear, so the
        // failure is signalled after the transaction commits rather than by throwing inside it (which
        // would roll the clear back). NoTransferPending writes nothing, so returning it is equivalent.
        $failure = DB::transaction(function () use ($actor, $group): ?GroupActionFailure {
            $locked = Group::whereKey($group->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->pending_admin_member_id !== (int) $actor->getKey()) {
                return GroupActionFailure::NoTransferPending;
            }

            // The nominee left (quit/dropped/withdrawn) after the request: clear the dangling pending.
            if (! GroupMembership::isMember($locked, $actor)) {
                $locked->pending_admin_member_id = null;
                $locked->save();

                return GroupActionFailure::NotMember;
            }

            GroupMember::query()
                ->where('group_id', $locked->getKey())
                ->where('role', GroupRole::Admin->value)
                ->update(['role' => GroupRole::Member]);

            GroupMember::query()
                ->where('group_id', $locked->getKey())
                ->where('member_id', $actor->getKey())
                ->update(['role' => GroupRole::Admin]);

            $locked->pending_admin_member_id = null;
            $locked->save();

            return null;
        });

        ViewerRelations::flush();

        if ($failure !== null) {
            throw new GroupActionException($failure);
        }
    }
}
