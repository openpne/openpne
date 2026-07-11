<?php

namespace App\Listeners\Security;

use App\Support\SecurityLog;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

class LogTwoFactorFailure
{
    public function handle(TwoFactorAuthenticationFailed $event): void
    {
        SecurityLog::event('mfa.failed', [
            'guard' => 'member',
            'member_id' => $event->user->getAuthIdentifier(),
        ]);
    }
}
