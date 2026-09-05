<?php

namespace App\Listeners\GroupEvent;

use App\Features\GroupEvent\Events\EventPosted;
use App\Jobs\BroadcastEventPosted;

/** Queued because the audience is community-wide and must not be walked in the request. */
class NotifyEventPosted
{
    public function handle(EventPosted $event): void
    {
        BroadcastEventPosted::dispatch((int) $event->event->getKey());
    }
}
