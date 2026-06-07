<?php

namespace App\Features\CommunityTopic\Actions;

use App\Features\CommunityTopic\CommunityTopicAccess;
use App\Features\CommunityTopic\Exceptions\CommunityTopicActionException;
use App\Features\CommunityTopic\Exceptions\CommunityTopicActionFailure;
use App\Models\CommunityTopicComment;
use App\Models\Member;

class DeleteTopicComment
{
    public function __invoke(Member $actor, CommunityTopicComment $comment): void
    {
        if (! CommunityTopicAccess::canDeleteComment($comment, $actor)) {
            throw new CommunityTopicActionException(CommunityTopicActionFailure::CannotDeleteComment);
        }

        // OpenPNE 3 leaves the remaining numbers and the topic timestamps untouched on delete.
        $comment->delete();
    }
}
