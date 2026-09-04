<?php

namespace App\Auth;

use Illuminate\Contracts\Session\Session;

/**
 * One window per subclass, never a shared stamp: a password proven for one flow must not also open
 * another. The window is deliberately short, bounding how long a walked-up session can trade on
 * someone else's proof.
 */
abstract class ReauthWindow
{
    private const WINDOW_SECONDS = 15 * 60;

    /** Where this flow's stamp is kept. Distinct per flow is the point of the subclass. */
    abstract protected static function sessionKey(): string;

    public static function stamp(Session $session): void
    {
        $session->put(static::sessionKey(), now()->getTimestamp());
    }

    public static function isFresh(Session $session): bool
    {
        $stampedAt = $session->get(static::sessionKey());

        return is_int($stampedAt) && now()->getTimestamp() - $stampedAt <= self::WINDOW_SECONDS;
    }

    public static function clear(Session $session): void
    {
        $session->forget(static::sessionKey());
    }
}
