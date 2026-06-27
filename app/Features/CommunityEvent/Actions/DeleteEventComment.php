<?php

namespace App\Features\CommunityEvent\Actions;

use App\Features\CommunityEvent\CommunityEventAccess;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionException;
use App\Features\CommunityEvent\Exceptions\CommunityEventActionFailure;
use App\Models\CommunityEventComment;
use App\Models\Member;

class DeleteEventComment
{
    public function __invoke(Member $actor, CommunityEventComment $comment): void
    {
        if (! CommunityEventAccess::canDeleteComment($comment, $actor)) {
            throw new CommunityEventActionException(CommunityEventActionFailure::CannotDeleteComment);
        }

        $this->purge($comment);
    }

    /**
     * Delete the comment and purge its image bytes — no authorization. The admin moderation panel
     * calls this directly (the panel's `admin` guard is an AdminUser, not a Member); frontend callers
     * always go through __invoke.
     */
    public function purge(CommunityEventComment $comment): void
    {
        // Collect the comment's owned image Files before the cascade drops the *_image link rows;
        // their bytes (irreversible on a disk backend) are purged after the row is gone.
        $files = $comment->images()->with('file')->get()->pluck('file')->filter()->all();

        // OpenPNE 3 leaves the remaining numbers and the event timestamps untouched on delete.
        $comment->delete();

        foreach ($files as $file) {
            $file->delete(); // deleting the File purges its bytes
        }
    }
}
