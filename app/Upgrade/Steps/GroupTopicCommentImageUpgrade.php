<?php

namespace App\Upgrade\Steps;

/** OpenPNE 3 `community_topic_comment_image` → OpenPNE 4 `group_topic_comment_images`. */
class GroupTopicCommentImageUpgrade extends PostImageUpgrade
{
    protected string $source = 'community_topic_comment_image';

    protected string $target = 'group_topic_comment_images';
}
