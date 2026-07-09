<?php

namespace App\Listeners\CommunityEvent;

use App\Features\CommunityEvent\Events\EventPosted;
use App\Jobs\BroadcastEventPosted;

/** Hands the new-event fan-out to a queued job (the audience is community-wide). */
class NotifyEventPosted
{
    public function handle(EventPosted $event): void
    {
        BroadcastEventPosted::dispatch((int) $event->event->getKey());
    }
}
