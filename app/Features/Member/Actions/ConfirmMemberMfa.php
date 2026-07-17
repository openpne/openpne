<?php

namespace App\Features\Member\Actions;

use App\Auth\SessionRevocation;
use App\Models\Member;
use App\Models\MfaResetRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;

/**
 * Confirm a pending set-up: prove authenticator possession with a TOTP code, stamp the factor live,
 * and revoke the member's other sessions (a factor change is a credential change) — all in one
 * transaction so it cannot half-apply. The pending guard and the secret-match check re-evaluate
 * against the row locked FOR UPDATE, so a concurrent enable/confirm cannot slip a rotated secret in
 * between: confirming a stale secret's code would stamp the factor live against a secret the member
 * never scanned.
 *
 * Fortify raises an invalid code in its `confirmTwoFactorAuthentication` named bag; the controller
 * translates that into the default bag (and owns the re-auth window, flash, and alert). The
 * fail-closed mismatch thrown here is already default-bag and reaches the member unchanged.
 */
class ConfirmMemberMfa
{
    use SyncsCallerInstance;

    public function __construct(private readonly ConfirmTwoFactorAuthentication $confirm) {}

    public function __invoke(Member $viewer, string $code, string $exceptSessionId): void
    {
        $fresh = DB::transaction(function () use ($viewer, $code, $exceptSessionId): Member {
            $fresh = Member::whereKey($viewer->getKey())->lockForUpdate()->firstOrFail();
            abort_if($fresh->hasEnabledTwoFactorAuthentication(), 403);
            abort_if(blank($fresh->two_factor_secret), 403);

            // Fail closed if a concurrent enable rotated the pending secret out from under the loaded
            // viewer. The stored ciphertext is stable per row, so raw equality is exact.
            if ($viewer->two_factor_secret !== $fresh->two_factor_secret) {
                throw ValidationException::withMessages([
                    'code' => __('Your two-factor settings changed while this page was open. Please try again.'),
                ]);
            }

            ($this->confirm)($fresh, $code);
            SessionRevocation::revokeMember($fresh, $exceptSessionId);

            // Defense-in-depth for 失効契約 (a): the newly live factor must never inherit a reset link
            // issued against an earlier factor (TASK-122).
            MfaResetRequest::where('member_id', $fresh->getKey())->delete();

            return $fresh;
        });

        $this->syncCaller($viewer, $fresh);
    }
}
