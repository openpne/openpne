<?php

namespace App\Upgrade\Steps;

use App\Models\TimelinePost;

class TimelineReactionUpgrade extends NiceReactionUpgrade
{
    protected function letter(): string
    {
        return 'A';
    }

    protected function reactable(): string
    {
        return TimelinePost::class;
    }

    protected function landing(): string
    {
        return self::onActivity(ActivityThread::landsOnTimeline('activity_data'));
    }
}
