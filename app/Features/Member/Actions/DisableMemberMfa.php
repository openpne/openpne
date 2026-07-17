<?php

namespace App\Features\Member\Actions;

use App\Auth\SessionRevocation;
use App\Models\Member;
use App\Models\MfaResetRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * Disable a member's two-factor factor, returning whether a LIVE (confirmed) factor was removed —
 * only that is a credential change worth revoking sessions over and alerting on (the caller branches
 * its log/alert/redirect on the return). Cancelling an inert pending set-up is password- and
 * proof-free, so it stays side-effect-free.
 *
 * The state is re-derived from the row locked FOR UPDATE, not the request-time snapshot
 * ($stepUpValidated is that snapshot — whether the request demanded and verified the password): if
 * the factor went live under a pending-cancel request, removing it unproven is refused (fail closed).
 * A spent recovery code is consumed BEFORE the factor is wiped, so its RecoveryCodeReplaced audit log
 * (deferred to after-commit) records nothing when a later failure rolls the transaction back.
 */
class DisableMemberMfa
{
    use SyncsCallerInstance, VerifiesTotpProof;

    public function __construct(
        private readonly DisableTwoFactorAuthentication $disable,
        private readonly TwoFactorAuthenticationProvider $provider,
    ) {}

    public function __invoke(Member $viewer, bool $stepUpValidated, ?string $code, ?string $recoveryCode, ?string $exceptSessionId): bool
    {
        [$fresh, $wasEnabled] = DB::transaction(function () use ($viewer, $stepUpValidated, $code, $recoveryCode, $exceptSessionId): array {
            $fresh = Member::whereKey($viewer->getKey())->lockForUpdate()->firstOrFail();

            // Nothing to disable — don't revoke sessions over a no-op (stale tab double-submit).
            if (blank($fresh->two_factor_secret)) {
                return [$fresh, false];
            }

            $wasEnabled = $fresh->hasEnabledTwoFactorAuthentication();

            if ($wasEnabled) {
                // Fail closed if the request was validated as a pending cancel (no password, no
                // proof) while the factor went live under it: removing a live factor unproven is
                // exactly what the re-auth exists to stop.
                if (! $stepUpValidated) {
                    throw ValidationException::withMessages([
                        'current_password' => __('Your two-factor settings changed while this page was open. Please try again.'),
                    ]);
                }

                $matched = $this->verifySecondFactor($fresh, $code, $recoveryCode);
                if ($matched !== null) {
                    $fresh->replaceRecoveryCode($matched);
                }
            }

            ($this->disable)($fresh);
            if ($wasEnabled) {
                SessionRevocation::revokeMember($fresh, $exceptSessionId);
            }

            // 失効契約 (a): the factor this member had is gone, so any admin-issued reset link for it must
            // die too — otherwise a "send → self-disable → re-enable within the TTL" sequence would leave
            // the old link live against the new factor (TASK-122). Member is already locked above; the
            // global Member → mfa_reset_requests order holds.
            MfaResetRequest::where('member_id', $fresh->getKey())->delete();

            return [$fresh, $wasEnabled];
        });

        $this->syncCaller($viewer, $fresh);

        return $wasEnabled;
    }

    /**
     * The live factor's second proof, returning the recovery code to consume (or null for a TOTP
     * proof). A filled recovery code wins over a TOTP code (challenge parity). Throws under
     * 'recovery_code' / 'code' on no match.
     */
    private function verifySecondFactor(Member $fresh, ?string $code, ?string $recoveryCode): ?string
    {
        if ((string) $recoveryCode !== '') {
            $matched = collect($fresh->recoveryCodes())->first(fn (string $c): bool => hash_equals($c, (string) $recoveryCode));
            if ($matched === null) {
                throw ValidationException::withMessages([
                    'recovery_code' => __('The provided two factor recovery code was invalid.'),
                ]);
            }

            return $matched;
        }

        $this->verifyTotpCode($this->provider, $fresh, (string) $code);

        return null;
    }
}
