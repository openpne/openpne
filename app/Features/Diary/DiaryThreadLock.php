<?php

namespace App\Features\Diary;

use App\Models\Diary;
use App\Models\DiaryComment;
use Illuminate\Support\Facades\DB;

/**
 * Every write that touches a diary's or its comments' reactions takes this first, inside a
 * transaction: the diary row exclusively, then the comment when the target is one (docs/internals/diary.md, "Reactions").
 * Both are locking reads, so under REPEATABLE READ they see the latest committed row rather than the snapshot.
 */
final class DiaryThreadLock
{
    public static function hold(Diary|DiaryComment $row): bool
    {
        $diaryId = $row instanceof DiaryComment ? (int) $row->diary_id : (int) $row->getKey();

        $diary = DB::table('diaries')->where('id', $diaryId)->lockForUpdate()->value('id');

        if ($diary === null) {
            return false;
        }

        if ($row instanceof Diary) {
            return true;
        }

        $live = DB::table('diary_comments')->where('id', $row->getKey())->where('diary_id', $diaryId)->lockForUpdate()->value('id');

        return $live !== null;
    }
}
