<?php

namespace App\Listeners\Security;

use App\Support\SecurityLog;
use Laravel\Fortify\Events\RecoveryCodeReplaced;

class LogRecoveryCodeReplaced
{
    public function handle(RecoveryCodeReplaced $event): void
    {
        // A member spent a recovery code at login — never log $event->code.
        SecurityLog::event('mfa.recovery_code_used', [
            'guard' => 'member',
            'member_id' => $event->user->getAuthIdentifier(),
        ]);
    }
}
