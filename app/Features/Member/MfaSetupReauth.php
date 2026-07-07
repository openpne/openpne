<?php

namespace App\Features\Member;

use Illuminate\Contracts\Session\Session;

/**
 * The set-up flow's re-authentication window: enable verifies the account password and stamps
 * the session; confirm accepts the stamp instead of asking for the password a second time in
 * the same sitting (the sudo-mode convention — one re-auth per sensitive flow, not per step).
 * The window is deliberately short: it only needs to span scanning a QR code, and it bounds how
 * long a walked-up pending set-up can be confirmed without the password.
 */
class MfaSetupReauth
{
    private const SESSION_KEY = 'mfa.password_confirmed_at';

    private const WINDOW_SECONDS = 15 * 60;

    public static function stamp(Session $session): void
    {
        $session->put(self::SESSION_KEY, now()->getTimestamp());
    }

    public static function isFresh(Session $session): bool
    {
        $stampedAt = $session->get(self::SESSION_KEY);

        return is_int($stampedAt) && now()->getTimestamp() - $stampedAt <= self::WINDOW_SECONDS;
    }

    public static function clear(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }
}
