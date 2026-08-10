<?php

namespace App\Features\Timeline\Events;

use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A member posted to their own timeline. Dispatched after the creating transaction commits. */
class TimelinePostPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<int>  $mentionedMemberIds  the distinct members the post's stored mentions name,
     *                                         snapshotted at write time so every listener splits one
     *                                         audience the same way: the mention notification sends to
     *                                         this set and the broadcast fan-outs subtract it, which is
     *                                         what keeps a mentioned member from being told twice.
     */
    public function __construct(
        public readonly TimelinePost $post,
        public readonly Member $author,
        public readonly array $mentionedMemberIds,
    ) {}
}
