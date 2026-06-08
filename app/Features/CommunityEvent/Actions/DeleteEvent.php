<?php

namespace App\Features\CommunityEvent\Actions;

use App\Features\CommunityEvent\CommunityEventAccess;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionException;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionFailure;
use App\Models\CommunityEvent;
use App\Models\Member;

class DeleteEvent
{
    public function __invoke(Member $actor, CommunityEvent $event): void
    {
        if (! CommunityEventAccess::canEditEvent($event, $actor)) {
            throw new CommunityEventActionException(CommunityEventActionFailure::CannotEdit);
        }

        $event->delete(); // FK cascade removes comments and participant rows
    }
}
