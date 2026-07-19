<?php

namespace App\Listeners\Community;

use App\Features\Community\Events\SubAdminAppointed;
use App\Notifications\Community\SubAdminAppointedNotification;

class NotifySubAdminAppointed
{
    public function handle(SubAdminAppointed $event): void
    {
        $event->appointee->notify(
            (new SubAdminAppointedNotification($event->community, $event->appointer))
                ->locale($event->appointee->locale ?? app()->getLocale()),
        );
    }
}
