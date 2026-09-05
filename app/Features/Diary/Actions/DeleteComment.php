<?php

namespace App\Features\Diary\Actions;

use App\Features\Diary\Exceptions\DiaryActionException;
use App\Features\Diary\Exceptions\DiaryActionFailure;
use App\Models\DiaryComment;
use App\Models\Member;

class DeleteComment
{
    public function __invoke(Member $actor, DiaryComment $comment): void
    {
        if (! $comment->isDeletableBy($actor)) {
            throw new DiaryActionException(DiaryActionFailure::NotAuthor);
        }

        $this->purge($comment);
    }

    /** No authorization: the `purge()` half of the Action split (docs/internals/feature-modules.md, "Surface responsibilities"). */
    public function purge(DiaryComment $comment): void
    {
        // Collect the comment's owned image Files before the cascade drops the *_image link rows;
        // their bytes (irreversible on a disk backend) are purged after the row is gone.
        $files = $comment->images()->with('file')->get()->pluck('file')->filter()->values()->all();

        $comment->delete();

        foreach ($files as $file) {
            $file->delete();
        }
    }
}
