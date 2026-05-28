<?php

namespace App\Features\Friend\Events;

use App\Models\Member;
use Illuminate\Foundation\Events\Dispatchable;

class FriendRequestAccepted
{
    use Dispatchable;

    public function __construct(
        public readonly Member $requester,
        public readonly Member $accepter,
    ) {}
}
