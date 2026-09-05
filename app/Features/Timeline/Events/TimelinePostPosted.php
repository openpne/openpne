<?php

namespace App\Features\Timeline\Events;

use App\Models\Member;
use App\Models\TimelinePost;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class TimelinePostPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<int>  $mentionedMemberIds  the distinct members the post's mentions name, snapshotted
     *                                         at write time as the only input to notification precedence
     *                                         (docs/internals/timeline.md, "Notifications")
     */
    public function __construct(
        public readonly TimelinePost $post,
        public readonly Member $author,
        public readonly array $mentionedMemberIds,
    ) {}
}
