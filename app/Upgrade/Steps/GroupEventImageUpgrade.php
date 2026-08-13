<?php

namespace App\Upgrade\Steps;

/** OpenPNE 3 `community_event_image` → OpenPNE 4 `group_event_images`. */
class GroupEventImageUpgrade extends PostImageUpgrade
{
    protected string $source = 'community_event_image';

    protected string $target = 'group_event_images';
}
