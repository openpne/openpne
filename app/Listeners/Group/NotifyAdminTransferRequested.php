<?php

namespace App\Listeners\Group;

use App\Features\Group\Events\AdminTransferRequested;
use App\Notifications\Group\AdminTransferRequestedNotification;

class NotifyAdminTransferRequested
{
    public function handle(AdminTransferRequested $event): void
    {
        $event->nominee->notify(
            (new AdminTransferRequestedNotification($event->group, $event->requester))
                ->locale($event->nominee->locale ?? app()->getLocale()),
        );
    }
}
