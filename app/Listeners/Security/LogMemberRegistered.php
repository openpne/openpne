<?php

namespace App\Listeners\Security;

use App\Features\Auth\Events\MemberRegistered;
use App\Support\SecurityLog;

class LogMemberRegistered
{
    public function handle(MemberRegistered $event): void
    {
        SecurityLog::event('member.registered', [
            'guard' => 'member',
            'member_id' => $event->member->getKey(),
        ]);
    }
}
