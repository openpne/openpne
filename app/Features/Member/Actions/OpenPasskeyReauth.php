<?php

namespace App\Features\Member\Actions;

use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * Only reached after the password rule passed at the request boundary. `$secondFactorDemanded` is the
 * request-time snapshot of whether the factor was live, re-checked under the lock so a concurrent
 * change fails closed.
 */
class OpenPasskeyReauth
{
    use SyncsCallerInstance, VerifiesTotpProof;

    public function __construct(private readonly TwoFactorAuthenticationProvider $provider) {}

    public function __invoke(Member $viewer, bool $secondFactorDemanded, ?string $code, ?string $recoveryCode): void
    {
        $fresh = DB::transaction(function () use ($viewer, $secondFactorDemanded, $code, $recoveryCode): Member {
            $fresh = Member::whereKey($viewer->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->hasEnabledTwoFactorAuthentication() !== $secondFactorDemanded) {
                throw ValidationException::withMessages([
                    'current_password' => __('Your two-factor settings changed while this page was open. Please try again.'),
                ]);
            }

            if ($secondFactorDemanded) {
                $matched = $this->verifySecondFactor($fresh, $code, $recoveryCode);
                if ($matched !== null) {
                    $fresh->replaceRecoveryCode($matched);
                }
            }

            return $fresh;
        });

        $this->syncCaller($viewer, $fresh);
    }

    /** A filled recovery code wins over a TOTP code, as the login challenge does. */
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
