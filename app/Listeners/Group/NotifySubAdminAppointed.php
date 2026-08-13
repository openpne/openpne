<?php

namespace App\Listeners\Group;

use App\Features\Group\Events\SubAdminAppointed;
use App\Notifications\Group\SubAdminAppointedNotification;

class NotifySubAdminAppointed
{
    public function handle(SubAdminAppointed $event): void
    {
        $event->appointee->notify(
            (new SubAdminAppointedNotification($event->group, $event->appointer))
                ->locale($event->appointee->locale ?? app()->getLocale()),
        );
    }
}
