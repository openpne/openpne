<?php

namespace App\Features\Group\Events;

use App\Models\Group;
use App\Models\Member;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A group admin nominated another member to take over the sole admin seat (awaiting acceptance). */
class AdminTransferRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Group $group,
        public readonly Member $requester,
        public readonly Member $nominee,
    ) {}
}
