<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

/** Other members' diaries the viewer commented on, latest comment first (OpenPNE 3 diaryCommentHistory). */
class DiaryCommentHistoryGadget extends DiaryListGadget
{
    public function name(): string
    {
        return 'diaryCommentHistory';
    }

    public function label(): string
    {
        return __('%Diary% Comment History');
    }

    public function description(): string
    {
        return __('%Diaries% you commented on.');
    }

    public function component(): string
    {
        return 'gadget.diary-comment-history';
    }
}
