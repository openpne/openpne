<?php

namespace App\Upgrade\Steps;

/** OpenPNE 3 `community_topic_image` → OpenPNE 4 `community_topic_images`. */
class CommunityTopicImageUpgrade extends PostImageUpgrade
{
    protected string $source = 'community_topic_image';

    protected string $target = 'community_topic_images';
}
