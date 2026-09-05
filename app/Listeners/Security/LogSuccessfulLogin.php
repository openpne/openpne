<?php

namespace App\Listeners\Security;

use App\Support\SecurityLog;
use Illuminate\Auth\Events\Login;

/**
 * Security event listeners are synchronous by design, never ShouldQueue: a worker no longer holds
 * the originating request (docs/internals/logging.md, "Queue workers").
 */
class LogSuccessfulLogin
{
    public function handle(Login $event): void
    {
        SecurityLog::event('login.success', [
            'guard' => $event->guard,
            'remember' => (bool) $event->remember,
        ] + ActorContext::of($event->guard, $event->user));
    }
}
