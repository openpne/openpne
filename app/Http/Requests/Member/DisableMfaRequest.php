<?php

namespace App\Http\Requests\Member;

use App\Models\Member;

/**
 * Disabling a confirmed factor weakens the account, so it always re-authenticates. Cancelling a
 * pending set-up does not: the pending secret gates nothing, so demanding the password would
 * only punish the member for abandoning a wizard.
 */
class DisableMfaRequest extends MfaManagementRequest
{
    protected function requiresPassword(): bool
    {
        $viewer = $this->user();
        assert($viewer instanceof Member);

        return $viewer->hasEnabledTwoFactorAuthentication();
    }
}
