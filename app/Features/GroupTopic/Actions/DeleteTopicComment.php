<?php

namespace App\Features\GroupTopic\Actions;

use App\Features\GroupTopic\Exceptions\GroupTopicActionException;
use App\Features\GroupTopic\Exceptions\GroupTopicActionFailure;
use App\Features\GroupTopic\GroupTopicAccess;
use App\Models\GroupTopicComment;
use App\Models\Member;

class DeleteTopicComment
{
    public function __invoke(Member $actor, GroupTopicComment $comment): void
    {
        if (! GroupTopicAccess::canDeleteComment($comment, $actor)) {
            throw new GroupTopicActionException(GroupTopicActionFailure::CannotDeleteComment);
        }

        $this->purge($comment);
    }

    /** No authorization: the `purge()` half of the Action split (docs/internals/feature-modules.md, "Surface responsibilities"). */
    public function purge(GroupTopicComment $comment): void
    {
        // Collect the comment's owned image Files before the cascade drops the *_image link rows;
        // their bytes (irreversible on a disk backend) are purged after the row is gone.
        $files = $comment->images()->with('file')->get()->pluck('file')->filter()->all();

        // OpenPNE 3 leaves the remaining numbers and the topic timestamps untouched on delete.
        $comment->delete();

        foreach ($files as $file) {
            $file->delete();
        }
    }
}
