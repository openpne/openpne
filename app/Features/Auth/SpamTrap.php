<?php

namespace App\Features\Auth;

use Illuminate\Http\Request;

/**
 * Both JS-free filters fail silently: the caller shows the neutral screen either way, so a bot gets
 * no signal it was caught. The form-open stamp is consumed by the POST (one-shot), so a submit
 * without a fresh render of its own, or one arriving implausibly fast, fails.
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

        $openedAt = $request->session()->pull(self::SESSION_KEY);

        return is_numeric($openedAt)
            && (now()->timestamp - (int) $openedAt) >= (int) config('openpne.registration.min_form_seconds');
    }
}
