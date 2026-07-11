<?php

namespace App\Listeners\Security;

use App\Support\SecurityLog;
use Illuminate\Auth\Events\Logout;

class LogLogout
{
    public function handle(Logout $event): void
    {
        SecurityLog::event('logout', ['guard' => $event->guard] + ActorContext::of($event->guard, $event->user));
    }
}
