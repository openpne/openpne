<?php

namespace App\Upgrade\Steps;

/** OpenPNE 3 `community_topic_image` → OpenPNE 4 `group_topic_images`. */
class GroupTopicImageUpgrade extends PostImageUpgrade
{
    protected string $source = 'community_topic_image';

    protected string $target = 'group_topic_images';
}
