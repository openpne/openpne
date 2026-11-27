<?php

namespace App\Listeners\Security;

use App\Support\SecurityLog;
use Illuminate\Auth\Events\Logout;

class LogLogout
{
    public function handle(Logout $event): void
    {
        SecurityLog::event('logout', ['guard' => $event->guard] + ($event->user === null ? [] : ['member_id' => $event->user->getAuthIdentifier()]));
    }
}
