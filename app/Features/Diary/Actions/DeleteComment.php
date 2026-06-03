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

        $comment->delete();
    }
}
