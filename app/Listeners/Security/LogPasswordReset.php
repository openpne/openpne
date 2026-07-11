<?php

namespace App\Listeners\Security;

use App\Support\SecurityLog;
use Illuminate\Auth\Events\PasswordReset;

class LogPasswordReset
{
    public function handle(PasswordReset $event): void
    {
        // Fortify's reset is member-only (an admin resets via CLI, logged at that seam).
        SecurityLog::event('password.reset', [
            'guard' => 'member',
            'member_id' => $event->user->getAuthIdentifier(),
        ]);
    }
}
