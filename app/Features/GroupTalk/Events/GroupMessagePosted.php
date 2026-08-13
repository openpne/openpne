<?php

namespace App\Features\GroupTalk\Events;

use App\Models\GroupMessage;
use App\Models\Member;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A member said something in a group's talk. Dispatched after the writing transaction commits. */
class GroupMessagePosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<int>  $mentionedMemberIds  the distinct members the stored mention rows name,
     *                                         snapshotted inside the write. Talk has no broadcast to
     *                                         subtract it from — a mention is the only notification
     *                                         it sends — but the snapshot still comes from the rows
     *                                         that were written, never from re-parsing the body.
     */
    public function __construct(
        public readonly GroupMessage $message,
        public readonly Member $author,
        public readonly array $mentionedMemberIds,
    ) {}
}
