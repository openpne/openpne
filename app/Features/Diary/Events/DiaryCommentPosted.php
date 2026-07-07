<?php

namespace App\Features\Diary\Events;

use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class DiaryCommentPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Diary $diary,
        public readonly DiaryComment $comment,
        public readonly Member $commenter,
    ) {}
}
