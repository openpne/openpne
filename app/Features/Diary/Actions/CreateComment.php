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
     * viewability). `number` is the per-diary sequence; computed under a row lock so two
     * concurrent posts cannot claim the same number — OpenPNE 3's naive max+1 could.
     */
    public function __invoke(Member $author, Diary $diary, string $body): DiaryComment
    {
        return DB::transaction(function () use ($author, $diary, $body): DiaryComment {
            $number = (int) $diary->comments()->lockForUpdate()->max('number') + 1;

            return $diary->comments()->create([
                'member_id' => $author->getKey(),
                'number' => $number,
                'body' => $body,
            ]);
        });
    }
}
