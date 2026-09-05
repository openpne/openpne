<?php

declare(strict_types=1);

namespace App\Features\AiAccount;

use App\Auth\ReauthWindow;

/**
 * See docs/internals/security.md, "AI account access tokens".
 */
final class AiTokenReauth extends ReauthWindow
{
    protected static function sessionKey(): string
    {
        return 'ai_token.password_confirmed_at';
    }
}
