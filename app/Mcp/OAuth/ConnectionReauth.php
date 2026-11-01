<?php

declare(strict_types=1);

namespace App\Mcp\OAuth;

use App\Auth\ReauthWindow;

/** Ending a connection has a window of its own: a password typed to end one must not approve a new one. */
final class ConnectionReauth extends ReauthWindow
{
    protected static function sessionKey(): string
    {
        return 'oauth_connection.password_confirmed_at';
    }
}
