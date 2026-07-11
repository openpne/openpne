<?php

namespace App\Listeners\Security;

use App\Support\SecurityLog;
use Illuminate\Auth\Events\Lockout;

class LogLockout
{
    public function handle(Lockout $event): void
    {
        SecurityLog::event('login.lockout', [
            'identifier' => $event->request->input('email') ?? $event->request->input('username'),
        ]);
    }
}
