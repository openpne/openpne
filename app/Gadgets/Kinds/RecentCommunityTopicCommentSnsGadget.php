<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

/** Recent topics across every public community (OpenPNE 3 recentCommunityTopicCommentSns). */
class RecentCommunityTopicCommentSnsGadget extends CommunityRecentListGadget
{
    public function name(): string
    {
        return 'recentCommunityTopicCommentSns';
    }

    public function description(): string
    {
        return __('Recently posted %topics% in public %communities%.');
    }

    public function component(): string
    {
        return 'gadget.recent-community-topic-comment-sns';
    }
}
