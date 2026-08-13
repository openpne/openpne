<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Support\Feature;

/** Recent events across every public community (OpenPNE 3 recentCommunityEventCommentSns). */
class RecentCommunityEventCommentSnsGadget extends GroupRecentListGadget
{
    public function name(): string
    {
        return 'recentCommunityEventCommentSns';
    }

    public function description(): string
    {
        return __('Recently posted events in public %communities%.');
    }

    public function component(): string
    {
        return 'gadget.recent-community-event-comment-sns';
    }

    public function feature(): ?Feature
    {
        return Feature::CommunityEvent;
    }
}
