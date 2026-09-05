<?php

namespace App\Features\GroupEvent\Actions;

use App\Features\GroupEvent\Exceptions\GroupEventActionException;
use App\Features\GroupEvent\Exceptions\GroupEventActionFailure;
use App\Features\GroupEvent\GroupEventAccess;
use App\Models\GroupEventComment;
use App\Models\Member;

class DeleteEventComment
{
    public function __invoke(Member $actor, GroupEventComment $comment): void
    {
        if (! GroupEventAccess::canDeleteComment($comment, $actor)) {
            throw new GroupEventActionException(GroupEventActionFailure::CannotDeleteComment);
        }

        $this->purge($comment);
    }

    /** No authorization: the `purge()` half of the Action split (docs/internals/feature-modules.md, "Surface responsibilities"). */
    public function purge(GroupEventComment $comment): void
    {
        // Collect the comment's owned image Files before the cascade drops the *_image link rows;
        // their bytes (irreversible on a disk backend) are purged after the row is gone.
        $files = $comment->images()->with('file')->get()->pluck('file')->filter()->all();

        // OpenPNE 3 leaves the remaining numbers and the event timestamps untouched on delete.
        $comment->delete();

        foreach ($files as $file) {
            $file->delete();
        }
    }
}
