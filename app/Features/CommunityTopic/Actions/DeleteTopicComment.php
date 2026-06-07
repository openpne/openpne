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

        // Collect the comment's owned image Files before the cascade drops the *_image link rows;
        // their bytes (irreversible on a disk backend) are purged after the row is gone.
        $files = $comment->images()->with('file')->get()->pluck('file')->filter()->all();

        // OpenPNE 3 leaves the remaining numbers and the topic timestamps untouched on delete.
        $comment->delete();

        foreach ($files as $file) {
            $file->delete(); // FileObserver purges the bytes
        }
    }
}
