<?php

namespace App\Listeners\Security;

use App\Support\SecurityLog;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Laravel\Fortify\Events\RecoveryCodeReplaced;

/**
 * After-commit so the audit record follows the code's actual fate: when a recovery code is spent
 * inside a transaction (disabling two-factor from settings), a rollback must leave no
 * "code used" record. The login challenge spends codes outside any transaction, where the
 * dispatcher runs this immediately — unchanged.
 */
class LogRecoveryCodeReplaced implements ShouldHandleEventsAfterCommit
{
    public function handle(RecoveryCodeReplaced $event): void
    {
        // A member spent a recovery code — at the login challenge or when disabling two-factor from
        // settings — never log $event->code.
        SecurityLog::event('mfa.recovery_code_used', [
            'guard' => 'member',
            'member_id' => $event->user->getAuthIdentifier(),
        ]);
    }
}
