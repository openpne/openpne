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

        $this->purge($comment);
    }

    /**
     * Delete the comment and purge its image bytes — no authorization. The admin moderation panel
     * calls this directly (the panel's `admin` guard is an AdminUser, not a Member); frontend callers
     * always go through __invoke.
     */
    public function purge(CommunityTopicComment $comment): void
    {
        // Collect the comment's owned image Files before the cascade drops the *_image link rows;
        // their bytes (irreversible on a disk backend) are purged after the row is gone.
        $files = $comment->images()->with('file')->get()->pluck('file')->filter()->all();

        // OpenPNE 3 leaves the remaining numbers and the topic timestamps untouched on delete.
        $comment->delete();

        foreach ($files as $file) {
            $file->delete(); // deleting the File purges its bytes
        }
    }
}
