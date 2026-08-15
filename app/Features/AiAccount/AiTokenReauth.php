<?php

declare(strict_types=1);

namespace App\Features\AiAccount;

use App\Auth\ReauthWindow;

/**
 * The re-authentication window for an owner handing out or taking back an AI account's token.
 * Its own window, not the two-factor one: a set-up confirmed a minute ago is no reason to mint a
 * credential without asking again.
 */
final class AiTokenReauth extends ReauthWindow
{
    protected static function sessionKey(): string
    {
        return 'ai_token.password_confirmed_at';
    }
}
