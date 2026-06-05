<?php

namespace App\Features\Community\Events;

use App\Models\Community;
use App\Models\Member;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A member requested to join an Approval-policy community (awaiting admin approval). */
class CommunityJoinRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Community $community,
        public readonly Member $member,
    ) {}
}
