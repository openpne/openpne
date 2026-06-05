<?php

namespace App\Features\Community\Events;

use App\Models\Community;
use App\Models\Member;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A member became a confirmed member of a community (open join, or an approved request). */
class CommunityJoined implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Community $community,
        public readonly Member $member,
    ) {}
}
