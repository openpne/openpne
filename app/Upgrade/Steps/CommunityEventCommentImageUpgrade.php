<?php

namespace App\Upgrade\Steps;

/** OpenPNE 3 `community_event_comment_image` → OpenPNE 4 `community_event_comment_images`. */
class CommunityEventCommentImageUpgrade extends PostImageUpgrade
{
    protected string $source = 'community_event_comment_image';

    protected string $target = 'community_event_comment_images';
}
