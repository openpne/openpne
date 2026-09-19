<?php

namespace App\Upgrade\Steps;

use App\Models\DiaryComment;

class DiaryCommentReactionUpgrade extends NiceReactionUpgrade
{
    protected function letter(): string
    {
        return 'd';
    }

    protected function reactable(): string
    {
        return DiaryComment::class;
    }

    protected function landing(): string
    {
        return self::onRecord(self::RECORD_TABLES['d']);
    }
}
