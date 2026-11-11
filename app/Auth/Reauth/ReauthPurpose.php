<?php

namespace App\Auth\Reauth;

use App\Auth\ReauthWindow;
use App\Features\Member\MfaSetupReauth;
use App\Features\Member\PasskeyReauth;

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

    /** @return class-string<ReauthWindow>|null */
    public function window(): ?string
    {
        return match ($this) {
            self::MfaSetup => MfaSetupReauth::class,
            self::PasskeyAdd => PasskeyReauth::class,
            default => null,
        };
    }
}
