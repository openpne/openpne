<?php

namespace App\Auth\Reauth;

use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

trait VerifiesReauthProof
{
    /**
     * `$locked` must be the member row read FOR UPDATE in the caller's transaction. Only reached after
     * the password rule passed at the request boundary, so a wrong password never marks a TOTP code
     * used nor spends a recovery code.
     */
    private function verifyReauthProof(Member $locked, ReauthProof $proof): void
    {
        // The request-boundary rule read the member as loaded when the request began; a password
        // changed since must not be proven by what was true then.
        if ($proof->offered && ! $proof->viaPasskey && ! Hash::check($proof->password, (string) $locked->password)) {
            throw ValidationException::withMessages(['current_password' => __('validation.current_password')]);
        }

        if ($proof->offered && $proof->secondFactorDemanded === null) {
            return;
        }

        // A request that was asked for nothing reaches here only because the state it was validated
        // against has since changed.
        if (! $proof->offered || $locked->hasEnabledTwoFactorAuthentication() !== $proof->secondFactorDemanded) {
            throw ValidationException::withMessages([
                'current_password' => __('Your two-factor settings changed while this page was open. Please try again.'),
            ]);
        }

        if (! $proof->secondFactorDemanded) {
            return;
        }

        // A filled recovery code wins over a TOTP code, as the login challenge does.
        if (! $proof->comparesCode()) {
            $matched = collect($locked->recoveryCodes())
                ->first(fn (string $code): bool => hash_equals($code, (string) $proof->recoveryCode));

            if ($matched === null) {
                throw ValidationException::withMessages([
                    'recovery_code' => __('The provided two factor recovery code was invalid.'),
                ]);
            }

            $locked->replaceRecoveryCode($matched);

            return;
        }

        $provider = app(TwoFactorAuthenticationProvider::class);

        if (! $provider->verify(Fortify::currentEncrypter()->decrypt($locked->two_factor_secret), (string) $proof->code)) {
            throw ValidationException::withMessages([
                'code' => __('The provided two factor authentication code was invalid.'),
            ]);
        }

        // After the commit, so an Action that verifies inside its own transaction keeps the count when it rolls back.
        DB::afterCommit(fn () => app(ReauthCodeBudget::class)->clear($locked));
    }
}
