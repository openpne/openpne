<?php

namespace App\Features\Friend\Events;

use App\Models\Member;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class FriendRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Member $requester,
        public readonly Member $target,
    ) {}
}
