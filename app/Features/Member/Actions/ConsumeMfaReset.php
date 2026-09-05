<?php

namespace App\Features\Member\Actions;

use App\Models\Member;
use App\Models\MfaResetRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The member is a guest here, so the account password is the proof, checked with `Hash::check`
 * because the `current_password:member` rule needs an authenticated member. The controller never
 * pre-reads or pre-mutates: this takes an id, re-reads under the lock, and returns a result.
 *
 * @throws ValidationException
 */
class ConsumeMfaReset
{
    public function __invoke(int $memberId, string $rawToken, string $password): MfaResetResult
    {
        return DB::transaction(function () use ($memberId, $rawToken, $password): MfaResetResult {
            // Withdrawn between the controller's lookup and this lock: no member row, so a dead link — not
            // a firstOrFail 404, which would break the dead-link contract.
            $locked = Member::whereKey($memberId)->lockForUpdate()->first();
            if ($locked === null) {
                return MfaResetResult::invalid();
            }

            $row = MfaResetRequest::where('member_id', $locked->getKey())
                ->where('token', hash('sha256', $rawToken))
                ->lockForUpdate()
                ->first();

            // Gone or replaced (a re-send minted a new token on the same PK) between the controller's
            // lookup and this lock.
            if ($row === null) {
                return MfaResetResult::invalid();
            }

            // The controller's lookup could have crossed the expiry boundary; an expired row is left
            // for the scheduled prune.
            $ttl = (int) config('openpne.mfa_reset.token_ttl_minutes');
            if ($row->created_at === null || $row->created_at->lte(now()->subMinutes($ttl))) {
                return MfaResetResult::invalid();
            }

            // Cleared by another route after the link was issued: nothing to disable, the link is spent,
            // and checking before the password keeps a dead link from asking for one.
            if (! $locked->hasEnabledTwoFactorAuthentication()) {
                $row->delete();

                return MfaResetResult::alreadyOff();
            }

            // Proven before any mutation, so a wrong guess rolls back with the token unspent.
            if (! Hash::check($password, (string) $locked->password)) {
                throw ValidationException::withMessages([
                    'password' => __('The provided password was incorrect.'),
                ]);
            }

            // Nested in this transaction, which already holds the Member lock.
            app(ForceDisableMemberMfa::class)($locked);

            // Idempotent: ForceDisableMemberMfa deleted this same row.
            $row->delete();

            return MfaResetResult::reset($locked);
        });
    }
}
