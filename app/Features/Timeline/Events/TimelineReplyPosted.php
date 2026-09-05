<?php

namespace App\Features\Timeline\Events;

use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** The thread root is $reply->parent: replies are always one level deep. */
class TimelineReplyPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<int>  $mentionedMemberIds  the distinct members the reply's mentions name, snapshotted
     *                                         at write time as the only input to notification precedence
     *                                         (docs/internals/timeline.md, "Notifications")
     */
    public function __construct(
        public readonly TimelinePost $reply,
        public readonly Member $author,
        public readonly array $mentionedMemberIds,
    ) {}
}
