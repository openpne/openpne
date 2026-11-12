<?php

namespace App\Listeners\Security;

use App\Features\Member\ReauthPasskeyController;
use App\Support\SecurityLog;
use Laravel\Passkeys\Events\PasskeyVerified;

/**
 * "Verified" is the assertion's cryptographic check, which the package finishes before the login
 * gate runs: a banned member's attempt logs this without a `login.success`.
 */
class LogPasskeyVerified
{
    public function handle(PasskeyVerified $event): void
    {
        $purpose = request()->attributes->get(ReauthPasskeyController::PURPOSE_ATTRIBUTE);

        SecurityLog::event('passkey.verified', [
            'guard' => 'member',
            'member_id' => $event->user->getAuthIdentifier(),
            'passkey_id' => $event->passkey->getKey(),
        ] + ($purpose === null ? [] : ['via' => 'reauth', 'purpose' => $purpose]));
    }
}
