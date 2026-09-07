<?php

namespace App\Upgrade\Steps;

/** OpenPNE 3 `activity_image` of a timeline-landing activity → OpenPNE 4 `timeline_post_images`. */
class TimelinePostImageUpgrade extends ActivityImageUpgrade
{
    protected string $target = 'timeline_post_images';

    protected function parentColumn(): string
    {
        return 'timeline_post_id';
    }

    protected function landing(): string
    {
        return ActivityThread::landsOnTimeline('activity_data');
    }
}
