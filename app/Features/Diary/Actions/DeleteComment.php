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

    /**
     * Delete the comment and purge its image bytes — no authorization. The admin moderation
     * panel calls this directly (the panel's `admin` guard is an AdminUser, not a Member);
     * frontend callers always go through __invoke.
     */
    public function purge(DiaryComment $comment): void
    {
        // Collect the owned image Files before the row is gone: the FK cascade drops the
        // diary_comment_image link rows but never the File bytes. Purge them post-delete.
        $files = $comment->images()->with('file')->get()->pluck('file')->filter()->values()->all();

        $comment->delete();

        foreach ($files as $file) {
            $file->delete(); // deleting the File purges its bytes
        }
    }
}
