<?php

namespace App\Features\GroupEvent\Events;

use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\Member;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class EventCommentPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly GroupEvent $event,
        public readonly GroupEventComment $comment,
        public readonly Member $commenter,
    ) {}
}
