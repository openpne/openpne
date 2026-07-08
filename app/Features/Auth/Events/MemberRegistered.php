<?php

namespace App\Features\Auth\Events;

use App\Models\Member;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A member finished self-service registration (CompleteRegistration). Dispatched after the creating
 * transaction commits, so the row the registration-complete mail references is already durable.
 */
class MemberRegistered implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Member $member) {}
}
