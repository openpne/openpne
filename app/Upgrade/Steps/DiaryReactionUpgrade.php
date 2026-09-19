<?php

namespace App\Upgrade\Steps;

use App\Models\Diary;

class DiaryReactionUpgrade extends NiceReactionUpgrade
{
    protected function letter(): string
    {
        return 'D';
    }

    protected function reactable(): string
    {
        return Diary::class;
    }

    protected function landing(): string
    {
        return self::onRecord(self::RECORD_TABLES['D']);
    }
}
