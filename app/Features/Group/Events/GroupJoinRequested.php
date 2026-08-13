<?php

namespace App\Features\Group\Events;

use App\Models\Group;
use App\Models\Member;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A member requested to join an Approval-policy group (awaiting admin approval). */
class GroupJoinRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Group $group,
        public readonly Member $member,
    ) {}
}
