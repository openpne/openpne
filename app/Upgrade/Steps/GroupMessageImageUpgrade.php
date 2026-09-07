<?php

namespace App\Upgrade\Steps;

/** OpenPNE 3 `activity_image` of a talk-landing activity → OpenPNE 4 `group_message_images`. */
class GroupMessageImageUpgrade extends ActivityImageUpgrade
{
    protected string $target = 'group_message_images';

    protected function parentColumn(): string
    {
        return 'group_message_id';
    }

    protected function landing(): string
    {
        return ActivityThread::landsInGroup('activity_data');
    }
}
