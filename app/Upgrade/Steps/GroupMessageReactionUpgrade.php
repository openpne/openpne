<?php

namespace App\Upgrade\Steps;

use App\Models\GroupMessage;

class GroupMessageReactionUpgrade extends NiceReactionUpgrade
{
    protected function reactable(): string
    {
        return GroupMessage::class;
    }

    protected function landing(): string
    {
        return ActivityThread::landsInGroup('activity_data');
    }
}
