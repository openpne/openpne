<?php

namespace App\Features\Auth;

use App\Captcha\Captcha;
use Illuminate\Http\Request;

/**
 * The login form's render state (registration link, CAPTCHA gate). Shared by Fortify's login view
 * and the loginForm gadget so the two cannot drift; recomputing within a request is deterministic
 * (same IP, same throttle state).
 */
class LoginFormData
{
    /** @return array{registrationOpen: bool, captchaRequired: bool, challengeUrl: string} */
    public static function for(Request $request): array
    {
        $captcha = app(Captcha::class);

        return [
            // Show the "register" link only when open entry actually exists, so it is never a 404.
            'registrationOpen' => RegistrationMode::current()->allowsOpenRegistration(),
            // Surface the challenge once this IP has tripped the failure threshold, so the widget is
            // on the form before the gated submit needs it.
            'captchaRequired' => $captcha->enabled() && app(LoginThrottle::class)->challengeRequired((string) $request->ip()),
            'challengeUrl' => route('altcha.challenge'),
        ];
    }
}
