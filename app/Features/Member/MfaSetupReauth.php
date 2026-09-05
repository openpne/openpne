<?php

namespace App\Features\Member;

use App\Auth\ReauthWindow;

/**
 * The window need only span scanning a QR code, and it bounds how long a walked-up pending set-up can
 * be confirmed without the password (docs/internals/security.md, "Member two-factor authentication").
 */
class MfaSetupReauth extends ReauthWindow
{
    protected static function sessionKey(): string
    {
        return 'mfa.password_confirmed_at';
    }
}
