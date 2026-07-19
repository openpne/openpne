<?php

namespace App\Features\Community\Actions;

use App\Features\Community\CommunityMembership;
use App\Features\Community\CommunityRole;
use App\Features\Community\Exceptions\CommunityActionException;
use App\Features\Community\Exceptions\CommunityActionFailure;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

/**
 * Lock protocol for community role / pending_admin_member_id writes:
 *
 * Every action that changes a member's role or the pending-admin nominee opens with
 * `DB::transaction` and re-reads the community row `->lockForUpdate()`, then runs ALL of its role
 * guards against that locked re-read — never against a role snapshot taken before the lock. The
 * community row is the single serialization point, so concurrent appoint/demote/drop/transfer/quit
 * and admin withdrawal can't interleave: only one holds the lock at a time, and each sees the others'
 * committed effects. This is what keeps "exactly one admin per community" true under races (e.g. two
 * members accepting a transfer, or a transfer accepted while the old admin withdraws).
 *
 * The nominee promotes from Member or Sub-admin; the incumbent admin is demoted to Member and the
 * pending seat is cleared, all under the lock.
 *
 * Accepted edges (OpenPNE 3 behaves the same):
 *  - A transfer pending across the old admin's withdrawal survives it: WithdrawMember auto-promotes
 *    the longest-tenured member, and a later accept then demotes that successor. The nominee wins.
 *  - DeleteCommunity has a TOCTOU: an ex-admin's in-flight delete can complete after a transfer, since
 *    its irreversible byte purge runs outside this lock. The harm equals a delete done just before the
 *    transfer, so it is accepted rather than folding the purge into the lock.
 */
class AcceptAdminTransfer
{
    public function __invoke(Member $actor, Community $community): void
    {
        // The defensive NotMember arm must clear the dangling pending and keep that clear, so the
        // failure is signalled after the transaction commits rather than by throwing inside it (which
        // would roll the clear back). NoTransferPending writes nothing, so returning it is equivalent.
        $failure = DB::transaction(function () use ($actor, $community): ?CommunityActionFailure {
            $locked = Community::whereKey($community->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $locked->pending_admin_member_id !== (int) $actor->getKey()) {
                return CommunityActionFailure::NoTransferPending;
            }

            // The nominee left (quit/dropped/withdrawn) after the request: clear the dangling pending.
            if (! CommunityMembership::isMember($locked, $actor)) {
                $locked->pending_admin_member_id = null;
                $locked->save();

                return CommunityActionFailure::NotMember;
            }

            CommunityMember::query()
                ->where('community_id', $locked->getKey())
                ->where('role', CommunityRole::Admin->value)
                ->update(['role' => CommunityRole::Member]);

            CommunityMember::query()
                ->where('community_id', $locked->getKey())
                ->where('member_id', $actor->getKey())
                ->update(['role' => CommunityRole::Admin]);

            $locked->pending_admin_member_id = null;
            $locked->save();

            return null;
        });

        if ($failure !== null) {
            throw new CommunityActionException($failure);
        }
    }
}
