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
 * Returns whether a live (confirmed) factor was removed; only that is a credential change to revoke
 * sessions and alert over. `$stepUpValidated` is the request-time snapshot of whether the password
 * was demanded and verified.
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
                // Fail closed: the request was validated as a pending cancel, and the factor went
                // live under it.
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

            // A reset link must never survive a change in the factor's lifecycle
            // (docs/internals/security.md, "Member two-factor authentication").
            MfaResetRequest::where('member_id', $fresh->getKey())->delete();

            return [$fresh, $wasEnabled];
        });

        $this->syncCaller($viewer, $fresh);

        return $wasEnabled;
    }

    /**
     * Returns the recovery code to consume, or null for a TOTP proof. A filled recovery code wins
     * over a TOTP code, as the login challenge does.
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
