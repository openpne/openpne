<?php

namespace App\Features\Member;

use App\Auth\ReauthWindow;

/**
 * The two-factor set-up flow's re-authentication window: enable verifies the account password and
 * stamps the session; confirm accepts the stamp instead of asking for the password a second time in
 * the same sitting. It only needs to span scanning a QR code, and it bounds how long a walked-up
 * pending set-up can be confirmed without the password.
 */
class MfaSetupReauth extends ReauthWindow
{
    protected static function sessionKey(): string
    {
        return 'mfa.password_confirmed_at';
    }
}
