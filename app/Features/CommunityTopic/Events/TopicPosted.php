<?php

namespace App\Features\CommunityTopic\Events;

use App\Models\CommunityTopic;
use App\Models\Member;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A member created a new topic in a community. Dispatched after the creating transaction commits. */
class TopicPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly CommunityTopic $topic,
        public readonly Member $author,
    ) {}
}
