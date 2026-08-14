<?php

namespace App\Features\Member\Actions;

use App\Models\EmailChangeRequest;
use App\Models\Member;
use App\Models\MfaResetRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Commits a pending email change and consumes its token in one transaction, so a token can never
 * outlive the change it made (single-use). remember_token is rotated in the same write, killing every
 * "remember me" cookie so the changed login identifier cannot be re-used silently. The members.email
 * unique index is the final TOCTOU guard: if the address was claimed between the controller's check
 * and here, the insert throws QueryException and the caller voids the dead pending row.
 *
 * The pending row is re-read under a lock (by its unique token) at the top of the transaction, not
 * trusted from the controller's earlier read: a concurrent cancel or password-change purge may have
 * deleted it — or a re-request replaced it (same PK, new token) — in between. Gone/replaced returns
 * null so the login identifier is never flipped on a voided change; without this, a cancel could
 * report success while a racing confirm still changed the address off the stale row.
 *
 * @return Member|null the changed member, or null when the pending change was voided before commit
 *
 * @throws QueryException the new address was claimed between check and commit
 */
class ConfirmEmailChange
{
    public function __invoke(EmailChangeRequest $pending): ?Member
    {
        return DB::transaction(function () use ($pending): ?Member {
            $locked = EmailChangeRequest::where('token', $pending->token)->lockForUpdate()->first();
            if ($locked === null) {
                return null;
            }

            $member = Member::whereKey($locked->member_id)->lockForUpdate()->firstOrFail();

            $member->forceFill([
                'email' => $locked->new_email,
                'remember_token' => Str::random(60),
            ])->save();

            // 失効契約 (b): the registered address is the proof channel an admin-issued MFA reset link is
            // sent to, so changing it voids any pending reset — same compensating-control shape as a
            // password change voiding a pending email change. Member is locked above; the
            // global Member → mfa_reset_requests order holds.
            MfaResetRequest::where('member_id', $member->getKey())->delete();

            $locked->delete();

            return $member;
        });
    }
}
