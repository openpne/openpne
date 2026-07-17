<?php

namespace App\Features\Member\Actions;

use App\Models\Member;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

/**
 * Shared TOTP verification for the two-factor management actions (disable, regenerate), kept in one
 * place so the failure key and message cannot drift between the two call sites.
 */
trait VerifiesTotpProof
{
    /**
     * Verify a TOTP code against the fresh secret; throws under 'code' on mismatch. Only ever reached
     * after the password rule passed at the request boundary — a wrong password never writes to
     * Fortify's replay cache, so a right-password retry with the same code still succeeds.
     */
    private function verifyTotpCode(TwoFactorAuthenticationProvider $provider, Member $member, string $code): void
    {
        if (! $provider->verify(Fortify::currentEncrypter()->decrypt($member->two_factor_secret), $code)) {
            throw ValidationException::withMessages([
                'code' => __('The provided two factor authentication code was invalid.'),
            ]);
        }
    }
}
