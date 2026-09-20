<?php

namespace App\Features\Member;

use App\Auth\ReauthWindow;

/**
 * Opened by a password (and second-factor) proof, spent by one registration
 * (docs/internals/security.md, "Member passkeys").
 */
class PasskeyReauth extends ReauthWindow
{
    protected static function sessionKey(): string
    {
        return 'passkey.password_confirmed_at';
    }
}
