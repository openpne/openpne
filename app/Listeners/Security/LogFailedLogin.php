<?php

namespace App\Listeners\Security;

use App\Support\SecurityLog;
use Illuminate\Auth\Events\Failed;

class LogFailedLogin
{
    public function handle(Failed $event): void
    {
        // Only the attempted identifier — never $event->credentials, which also holds the password.
        $identifier = $event->credentials['email'] ?? $event->credentials['username'] ?? null;

        SecurityLog::event('login.failed', [
            'guard' => $event->guard,
            'identifier' => $identifier,
        ]);
    }
}
