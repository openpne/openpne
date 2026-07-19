<?php

namespace App\Features\Community\Events;

use App\Models\Community;
use App\Models\Member;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A community admin promoted a plain member to sub-admin. */
class SubAdminAppointed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Community $community,
        public readonly Member $appointer,
        public readonly Member $appointee,
    ) {}
}
