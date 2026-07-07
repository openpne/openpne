<?php

namespace App\Features\CommunityEvent\Events;

use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\Member;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class EventCommentPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly CommunityEvent $event,
        public readonly CommunityEventComment $comment,
        public readonly Member $commenter,
    ) {}
}
