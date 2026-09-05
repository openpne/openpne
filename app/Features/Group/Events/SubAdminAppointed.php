<?php

namespace App\Features\Group\Events;

use App\Models\Group;
use App\Models\Member;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class SubAdminAppointed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Group $group,
        public readonly Member $appointer,
        public readonly Member $appointee,
    ) {}
}
