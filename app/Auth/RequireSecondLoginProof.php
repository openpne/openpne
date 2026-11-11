<?php

namespace App\Auth;

use App\Features\Auth\LoginCode;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

/**
 * A member who holds a passkey never signs in on the password alone
 * (docs/internals/security.md, "Member passkeys"). Fortify resolves this step only while its
 * two-factor feature is on.
 */
class RequireSecondLoginProof extends RedirectIfTwoFactorAuthenticatable
{
    public function handle($request, $next)
    {
        $member = $this->validateCredentials($request);

        if ($member->hasEnabledTwoFactorAuthentication()) {
            $request->session()->forget(LoginCode::SLOT);

            return $this->twoFactorChallengeResponse($request, $member);
        }

        if (! $member->passkeys()->exists()) {
            return $next($request);
        }

        if (! app(LoginCode::class)->issue($request->session(), $member, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                Fortify::username() => __('Too many confirmation codes have been sent. Please try again later.'),
            ]);
        }

        return redirect()->route('login.code');
    }
}
