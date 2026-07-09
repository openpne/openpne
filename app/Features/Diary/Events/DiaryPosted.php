<?php

namespace App\Features\Diary\Events;

use App\Models\Diary;
use App\Models\Member;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A member posted a new diary. Dispatched after the creating transaction commits. */
class DiaryPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Diary $diary,
        public readonly Member $author,
    ) {}
}
