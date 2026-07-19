<?php

namespace App\Features\Community\Events;

use App\Models\Community;
use App\Models\Member;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A community admin nominated another member to take over the sole admin seat (awaiting acceptance). */
class AdminTransferRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Community $community,
        public readonly Member $requester,
        public readonly Member $nominee,
    ) {}
}
