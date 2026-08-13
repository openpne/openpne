<?php

namespace App\Features\GroupTopic\Events;

use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class TopicCommentPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly GroupTopic $topic,
        public readonly GroupTopicComment $comment,
        public readonly Member $commenter,
    ) {}
}
