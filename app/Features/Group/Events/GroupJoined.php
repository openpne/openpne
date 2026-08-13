<?php

namespace App\Features\Group\Events;

use App\Models\Group;
use App\Models\Member;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A member became a confirmed member of a group (open join, or an approved request). */
class GroupJoined implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Group $group,
        public readonly Member $member,
    ) {}
}
