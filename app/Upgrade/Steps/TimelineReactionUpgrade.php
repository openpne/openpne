<?php

namespace App\Upgrade\Steps;

use App\Models\TimelinePost;

class TimelineReactionUpgrade extends NiceReactionUpgrade
{
    protected function reactable(): string
    {
        return TimelinePost::class;
    }

    protected function landing(): string
    {
        return ActivityThread::landsOnTimeline('activity_data');
    }
}
