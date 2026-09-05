<?php

namespace App\Features\Member\Actions;

use App\Models\Member;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

trait VerifiesTotpProof
{
    /**
     * Only reached after the password rule passed at the request boundary, so a wrong password never
     * writes to Fortify's replay cache.
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
