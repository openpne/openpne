<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Support\Feature;

/** Recent topics across every public community (OpenPNE 3 recentCommunityTopicCommentSns). */
class RecentGroupTopicCommentSnsGadget extends GroupRecentListGadget
{
    public function name(): string
    {
        return 'recentGroupTopicCommentSns';
    }

    public function label(): string
    {
        return __('Recent %Community% %Topic% Comment Sns');
    }

    public function description(): string
    {
        return __('Recently posted %topics% in public %communities%.');
    }

    public function component(): string
    {
        return 'gadget.recent-group-topic-comment-sns';
    }

    public function feature(): ?Feature
    {
        return Feature::GroupTopic;
    }
}
