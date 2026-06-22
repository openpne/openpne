<?php

namespace App\Upgrade\Steps;

/** OpenPNE 3 `community_event_image` → OpenPNE 4 `community_event_images`. */
class CommunityEventImageUpgrade extends PostImageUpgrade
{
    protected string $source = 'community_event_image';

    protected string $target = 'community_event_images';
}
