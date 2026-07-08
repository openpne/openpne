<?php

namespace App\Features\Member\Actions;

use App\Models\Member;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

/**
 * Clears a member's two-factor factor, shared by the self-service disable and the operator lockout
 * CLI. Returns whether a live (confirmed) factor was actually removed — the signal a caller uses to
 * decide whether this is a credential change worth revoking sessions over and announcing
 * (MfaDisabledNotification). Cancelling a pending, unconfirmed set-up returns false, so neither caller
 * sends a false "your two-factor was turned off" alert for a factor that never protected a login.
 */
class DisableMemberMfa
{
    public function __construct(private readonly DisableTwoFactorAuthentication $disable) {}

    public function __invoke(Member $member): bool
    {
        $wasEnabled = $member->hasEnabledTwoFactorAuthentication();
        ($this->disable)($member);

        return $wasEnabled;
    }
}
