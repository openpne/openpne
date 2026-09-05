<?php

namespace App\Features\GroupTalk\Events;

use App\Models\GroupMessage;
use App\Models\Member;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class GroupMessagePosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<int>  $mentionedMemberIds  the distinct members the stored mention rows name,
     *                                         snapshotted inside the write rather than re-parsed
     *                                         from the body
     */
    public function __construct(
        public readonly GroupMessage $message,
        public readonly Member $author,
        public readonly array $mentionedMemberIds,
    ) {}
}
