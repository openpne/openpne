<?php

namespace App\Upgrade\Steps;

/** OpenPNE 3 `community_topic_comment_image` → OpenPNE 4 `community_topic_comment_images`. */
class CommunityTopicCommentImageUpgrade extends PostImageUpgrade
{
    protected string $source = 'community_topic_comment_image';

    protected string $target = 'community_topic_comment_images';
}
