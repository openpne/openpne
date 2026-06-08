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
