<?php

namespace App\Listeners\Community;

use App\Features\Community\Events\AdminTransferRequested;
use App\Notifications\Community\AdminTransferRequestedNotification;

class NotifyAdminTransferRequested
{
    public function handle(AdminTransferRequested $event): void
    {
        $event->nominee->notify(
            (new AdminTransferRequestedNotification($event->community, $event->requester))
                ->locale($event->nominee->locale ?? app()->getLocale()),
        );
    }
}
