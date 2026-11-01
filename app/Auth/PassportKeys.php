<?php

declare(strict_types=1);

namespace App\Auth;

use Laravel\Passport\Passport;

/**
 * Both halves of the signing pair, from config or from Passport's key path; one half alone counts
 * as absent. Routes and the guard consult this at boot, so a site opens OAuth by adding the keys
 * (docs/internals/mcp.md, "Signing in from a client").
 */
final class PassportKeys
{
    public static function present(): bool
    {
        if (config('passport.private_key') && config('passport.public_key')) {
            return true;
        }

        return is_readable(Passport::keyPath('oauth-private.key'))
            && is_readable(Passport::keyPath('oauth-public.key'));
    }
}
