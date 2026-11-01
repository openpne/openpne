<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\Member;
use BadMethodCallException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;

/**
 * The check `auth.session` makes at the door, repeated on a row the caller has locked: a request that
 * passed the door before a password change may otherwise persist a credential after the change's
 * sweep. Compares what the session was signed in with against the locked member's hash, transformed
 * the way the guard stores it.
 */
final class SessionCredential
{
    public static function stillHolds(Session $session, Member $locked): bool
    {
        $guard = Auth::getDefaultDriver();
        $signedInWith = $session->get('password_hash_'.$guard);
        $current = (string) $locked->getAuthPassword();

        try {
            $current = Auth::guard($guard)->hashPasswordForCookie($current);
        } catch (BadMethodCallException) {
        }

        return is_string($signedInWith) && hash_equals($signedInWith, $current);
    }
}
