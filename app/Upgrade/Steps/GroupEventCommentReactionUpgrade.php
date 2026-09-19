<?php

namespace App\Upgrade\Steps;

use App\Models\GroupEventComment;

class GroupEventCommentReactionUpgrade extends NiceReactionUpgrade
{
    protected function letter(): string
    {
        return 'e';
    }

    protected function reactable(): string
    {
        return GroupEventComment::class;
    }

    protected function landing(): string
    {
        return self::onRecord(self::RECORD_TABLES['e']);
    }
}
