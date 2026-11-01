<?php

declare(strict_types=1);

namespace App\Mcp\OAuth;

use App\Auth\ReauthWindow;

/** See docs/internals/security.md, "AI account access tokens". */
final class ConsentReauth extends ReauthWindow
{
    protected static function sessionKey(): string
    {
        return 'oauth_consent.password_confirmed_at';
    }
}
