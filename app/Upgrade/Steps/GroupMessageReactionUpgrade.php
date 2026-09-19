<?php

namespace App\Upgrade\Steps;

use App\Models\GroupMessage;

class GroupMessageReactionUpgrade extends NiceReactionUpgrade
{
    protected function letter(): string
    {
        return 'A';
    }

    protected function reactable(): string
    {
        return GroupMessage::class;
    }

    protected function landing(): string
    {
        return self::onActivity(ActivityThread::landsInGroup('activity_data'));
    }
}
