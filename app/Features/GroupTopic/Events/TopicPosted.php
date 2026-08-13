<?php

namespace App\Features\GroupTopic\Events;

use App\Models\GroupTopic;
use App\Models\Member;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A member created a new topic in a group. Dispatched after the creating transaction commits. */
class TopicPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly GroupTopic $topic,
        public readonly Member $author,
    ) {}
}
