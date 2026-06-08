<?php

namespace App\Features\Auth;

use Illuminate\Http\Request;

/**
 * Two JS-free bot filters for the registration entry, both failing *silently* — the caller still
 * shows the neutral "check your mail" screen, so a bot gets no signal it was caught (the same shape
 * as the enumeration-safe no-op for a known address):
 *  - a honeypot field a real person never sees and never fills;
 *  - a minimum fill time: the form-open instant is stamped in the session on GET, so a submit that
 *    arrives implausibly fast, or with no prior GET at all, is a script rather than a person.
 * This is the floor that works without JavaScript; ALTCHA is the stronger JS-based layer on top.
 */
class SpamTrap
{
    public const HONEYPOT = 'homepage';

    public const SESSION_KEY = 'register_form_opened_at';

    public function arm(Request $request): void
    {
        $request->session()->put(self::SESSION_KEY, now()->timestamp);
    }

    public function passes(Request $request): bool
    {
        if (filled($request->input(self::HONEYPOT))) {
            return false;
        }

        $openedAt = $request->session()->get(self::SESSION_KEY);

        return is_numeric($openedAt)
            && (now()->timestamp - (int) $openedAt) >= (int) config('openpne.registration.min_form_seconds');
    }
}
