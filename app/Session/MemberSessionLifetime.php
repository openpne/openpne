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

    /**
     * Null wherever the framework's lifetime stands. Asks the guard, so it must not run while the
     * session driver is being built.
     */
    public static function cookieMinutes(Request $request): ?int
    {
        if (config('session.driver') !== 'database'
            || config('session.expire_on_close')
            || AdminRealm::matches($request)
            || ! Auth::guard('member')->check()) {
            return null;
        }

        return self::idleMinutes();
    }
}
