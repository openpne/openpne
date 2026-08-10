<?php

namespace App\Features\Timeline\Events;

use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A member replied to a top-level post. Dispatched after the creating transaction commits; the
 * thread root is $reply->parent (replies are always one level deep).
 */
class TimelineReplyPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<int>  $mentionedMemberIds  the distinct members the reply's stored mentions name,
     *                                         snapshotted at write time so every listener splits one
     *                                         audience the same way: the mention notification sends to
     *                                         this set and the reply/related fan-outs subtract it, which
     *                                         is what keeps a mentioned member from being told twice.
     */
    public function __construct(
        public readonly TimelinePost $reply,
        public readonly Member $author,
        public readonly array $mentionedMemberIds,
    ) {}
}
