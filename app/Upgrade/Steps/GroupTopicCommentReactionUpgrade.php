<?php

namespace App\Upgrade\Steps;

use App\Models\GroupTopicComment;

class GroupTopicCommentReactionUpgrade extends NiceReactionUpgrade
{
    protected function letter(): string
    {
        return 't';
    }

    protected function reactable(): string
    {
        return GroupTopicComment::class;
    }

    protected function landing(): string
    {
        return self::onRecord(self::RECORD_TABLES['t']);
    }
}
