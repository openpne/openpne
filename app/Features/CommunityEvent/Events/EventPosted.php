<?php

namespace App\Features\CommunityEvent\Events;

use App\Models\CommunityEvent;
use App\Models\Member;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A member created a new event in a community. Dispatched after the creating transaction commits. */
class EventPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly CommunityEvent $event,
        public readonly Member $author,
    ) {}
}
