<?php

namespace App\Features\Diary\Actions;

use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use Illuminate\Support\Facades\DB;

class CreateComment
{
    /**
     * Append a comment to a diary the author may already view (the controller gates
     * viewability). `number` is the per-diary sequence. Lock the parent diary row first so
     * concurrent commenters serialize on a row that always exists: an empty thread has no
     * comment rows to lock, so `max(number)` alone would let two posts both claim 1. The
     * unique (diary_id, number) index is the DB-level backstop.
     */
    public function __invoke(Member $author, Diary $diary, string $body): DiaryComment
    {
        return DB::transaction(function () use ($author, $diary, $body): DiaryComment {
            Diary::whereKey($diary->getKey())->lockForUpdate()->first();

            $number = (int) $diary->comments()->max('number') + 1;

            return $diary->comments()->create([
                'member_id' => $author->getKey(),
                'number' => $number,
                'body' => $body,
            ]);
        });
    }
}
