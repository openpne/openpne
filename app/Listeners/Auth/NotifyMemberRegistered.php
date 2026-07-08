<?php

namespace App\Listeners\Auth;

use App\Features\Auth\Events\MemberRegistered;
use App\Notifications\Member\RegistrationCompletedNotification;

class NotifyMemberRegistered
{
    public function handle(MemberRegistered $event): void
    {
        $member = $event->member;

        $member->notify(new RegistrationCompletedNotification($member->locale ?? config('app.locale')));
    }
}
