<?php

namespace App\Session;

use App\Support\AdminRealm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** See docs/internals/sessions.md, "Lifetimes". */
final class MemberSessionLifetime
{
    /** Never below session.lifetime, so signing in cannot shorten a session. */
    public static function idleMinutes(): int
    {
        return max((int) config('session.member_lifetime'), (int) config('session.lifetime'));
    }

    /** Never below the idle lifetime, which an unset or zero value would otherwise undercut. */
    public static function absoluteMinutes(): int
    {
        return max((int) config('session.member_absolute_lifetime'), self::idleMinutes());
    }

    /**
     * Null wherever the framework's lifetime stands. Asks the guard, so it must not run while the
     * session driver is being built.
     */
    public static function cookieMinutes(Request $request): ?int
    {
        return Auth::guard('member')->check() ? self::memberCookieMinutes($request) : null;
    }

    /** cookieMinutes() without asking the guard, which fires Login before it holds the user. */
    public static function memberCookieMinutes(Request $request): ?int
    {
        if (config('session.driver') !== 'database'
            || config('session.expire_on_close')
            || AdminRealm::matches($request)) {
            return null;
        }

        return self::idleMinutes();
    }
}
