<?php

namespace App\Auth\Reauth;

use App\Auth\ReauthWindow;
use App\Features\AiAccount\AiTokenReauth;
use App\Features\Member\MfaSetupReauth;
use App\Features\Member\PasskeyReauth;
use App\Mcp\OAuth\ConnectionReauth;
use App\Mcp\OAuth\ConsentReauth;

/**
 * One case per step-up, so a proof given for one never opens another
 * (docs/internals/security.md, "Member re-authentication").
 */
enum ReauthPurpose: string
{
    case MfaSetup = 'mfa-setup';
    case MfaDisable = 'mfa-disable';
    case MfaRecoveryCodes = 'mfa-recovery-codes';
    case PasskeyAdd = 'passkey-add';
    case PasskeyRemove = 'passkey-remove';
    case PasswordChange = 'password-change';
    case EmailChange = 'email-change';
    case Withdrawal = 'withdrawal';
    case AiAccountDelete = 'ai-account-delete';
    case AiToken = 'ai-token';
    case OAuthConsent = 'oauth-consent';
    case OAuthConnectionRevoke = 'oauth-connection-revoke';

    /** @return class-string<ReauthWindow>|null */
    public function window(): ?string
    {
        return match ($this) {
            self::MfaSetup => MfaSetupReauth::class,
            self::PasskeyAdd => PasskeyReauth::class,
            self::AiToken => AiTokenReauth::class,
            self::OAuthConsent => ConsentReauth::class,
            self::OAuthConnectionRevoke => ConnectionReauth::class,
            default => null,
        };
    }
}
