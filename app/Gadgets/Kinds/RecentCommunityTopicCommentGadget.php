<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Support\Feature;

/** Recent topics across the communities the viewer joined (OpenPNE 3 recentCommunityTopicComment). */
class RecentCommunityTopicCommentGadget extends CommunityRecentListGadget
{
    public function name(): string
    {
        return 'recentCommunityTopicComment';
    }

    public function description(): string
    {
        return __('Recently posted %topics% in %communities% you have joined.');
    }

    public function component(): string
    {
        return 'gadget.recent-community-topic-comment';
    }

    public function feature(): ?Feature
    {
        return Feature::CommunityTopic;
    }
}
