<?php

namespace App\Features\Member\Actions;

use App\Auth\SessionRevocation;
use App\Models\Member;
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
 * (deferred to after-commit) records nothing when a later failure rolls the transaction back. The
 * proof is verified against the fresh row, but the consume/disable/revoke run on the authenticated
 * instance so the session's auth/flash state stays consistent.
 */
class DisableMemberMfa
{
    use VerifiesTotpProof;

    public function __construct(
        private readonly DisableTwoFactorAuthentication $disable,
        private readonly TwoFactorAuthenticationProvider $provider,
    ) {}

    public function __invoke(Member $viewer, bool $stepUpValidated, ?string $code, ?string $recoveryCode, ?string $exceptSessionId): bool
    {
        return DB::transaction(function () use ($viewer, $stepUpValidated, $code, $recoveryCode, $exceptSessionId): bool {
            $fresh = Member::whereKey($viewer->getKey())->lockForUpdate()->firstOrFail();

            // Nothing to disable — don't revoke sessions over a no-op (stale tab double-submit).
            if (blank($fresh->two_factor_secret)) {
                return false;
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
                    $viewer->replaceRecoveryCode($matched);
                }
            }

            ($this->disable)($viewer);
            if ($wasEnabled) {
                SessionRevocation::revokeMember($viewer, $exceptSessionId);
            }

            return $wasEnabled;
        });
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
