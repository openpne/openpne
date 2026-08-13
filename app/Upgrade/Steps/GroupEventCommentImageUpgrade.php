<?php

namespace App\Upgrade\Steps;

/** OpenPNE 3 `community_event_comment_image` → OpenPNE 4 `group_event_comment_images`. */
class GroupEventCommentImageUpgrade extends PostImageUpgrade
{
    protected string $source = 'community_event_comment_image';

    protected string $target = 'group_event_comment_images';
}
