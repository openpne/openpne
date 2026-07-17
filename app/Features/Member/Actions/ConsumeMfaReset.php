<?php

namespace App\Features\Member\Actions;

use App\Models\Member;
use App\Models\MfaResetRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Consume an admin-issued reset link: verify the member's account password and clear their two-factor
 * factor, burning the token in the same transaction (single-use). The member is a guest here (locked
 * out), so the account password is the identity proof — checked with Hash::check, since the guest context
 * cannot use the `current_password:member` rule.
 *
 * Ordering is load-bearing (nothing is spent on a wrong password): the password is verified BEFORE any
 * mutation in the live branch, so a failed check throws and rolls the whole transaction back with the
 * token intact. The pending row and the member state are re-derived under locks, not trusted from the
 * controller's earlier unlocked lookup — a concurrent disable/re-send/expiry between the lookup and here
 * must not disable a factor off a stale link.
 *
 * Global lock order is Member → mfa_reset_requests (shared with ForceDisableMemberMfa, whose 失効契約
 * delete runs under the Member lock this already holds): lock the Member row first, then the token row.
 *
 * @return Member|null the member whose live factor was cleared, or null when the link was already gone,
 *                     expired, or the factor was no longer live (nothing to disable) — the token is burned
 *                     in the latter cases too
 *
 * @throws ValidationException the account password did not match (rolls back; the token survives)
 */
class ConsumeMfaReset
{
    public function __invoke(Member $member, string $rawToken, string $password): ?Member
    {
        return DB::transaction(function () use ($member, $rawToken, $password): ?Member {
            $locked = Member::whereKey($member->getKey())->lockForUpdate()->firstOrFail();

            $row = MfaResetRequest::where('member_id', $locked->getKey())
                ->where('token', hash('sha256', $rawToken))
                ->lockForUpdate()
                ->first();

            // Gone or replaced (a re-send minted a new token on the same PK) between the controller's
            // lookup and this lock.
            if ($row === null) {
                return null;
            }

            // Re-verify the TTL inside the transaction: the controller's lookup could have crossed the
            // expiry boundary. Leave an expired row for the scheduled prune (matching the email-change path).
            $ttl = (int) config('openpne.mfa_reset.token_ttl_minutes');
            if ($row->created_at === null || $row->created_at->lte(now()->subMinutes($ttl))) {
                return null;
            }

            // The factor was cleared by another route after the link was issued: nothing to disable, but
            // the link is spent — burn it. Checked before the password so a dead link never asks for one.
            if (! $locked->hasEnabledTwoFactorAuthentication()) {
                $row->delete();

                return null;
            }

            // Live branch: prove the account password before mutating anything. A mismatch throws and the
            // transaction rolls back, so the token is never spent on a wrong guess.
            if (! Hash::check($password, (string) $locked->password)) {
                throw ValidationException::withMessages([
                    'password' => __('The provided password was incorrect.'),
                ]);
            }

            // Clear the factor, revoke every session, and drop the pending row (ForceDisableMemberMfa's
            // 失効契約). Nested in this transaction: the Member lock is already held.
            app(ForceDisableMemberMfa::class)($locked);

            // Explicit single-use burn (ForceDisable's 失効契約 delete already removed this same row; the
            // delete is idempotent and documents intent).
            $row->delete();

            return $locked;
        });
    }
}
