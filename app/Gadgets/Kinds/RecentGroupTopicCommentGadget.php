<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Support\Feature;

/** Recent topics across the groups the viewer joined (OpenPNE 3 recentCommunityTopicComment). */
class RecentGroupTopicCommentGadget extends GroupRecentListGadget
{
    public function name(): string
    {
        return 'recentGroupTopicComment';
    }

    public function label(): string
    {
        return __('Recent %Community% %Topic% Comment');
    }

    public function description(): string
    {
        return __('Recently posted %topics% in %communities% you have joined.');
    }

    public function component(): string
    {
        return 'gadget.recent-group-topic-comment';
    }

    public function feature(): ?Feature
    {
        return Feature::GroupTopic;
    }
}
