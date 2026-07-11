<?php

namespace App\Listeners\Security;

use App\Support\SecurityLog;
use Illuminate\Auth\Events\Login;

/**
 * Security event listeners (this namespace) are synchronous by design: they are NOT ShouldQueue.
 * Queueing would serialise the event onto a worker that no longer has the originating request, so
 * the ip / user_agent SecurityLog auto-attaches would be lost, and events could land out of order
 * relative to the request that caused them. Auto-discovered by their handle() type hint.
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
