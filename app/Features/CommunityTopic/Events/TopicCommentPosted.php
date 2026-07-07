<?php

namespace App\Features\CommunityTopic\Events;

use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\Member;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class TopicCommentPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly CommunityTopic $topic,
        public readonly CommunityTopicComment $comment,
        public readonly Member $commenter,
    ) {}
}
