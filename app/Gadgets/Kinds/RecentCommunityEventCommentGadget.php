<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Support\Feature;

/** Recent events across the groups the viewer joined (OpenPNE 3 recentCommunityEventComment). */
class RecentCommunityEventCommentGadget extends CommunityRecentListGadget
{
    public function name(): string
    {
        return 'recentCommunityEventComment';
    }

    public function description(): string
    {
        return __('Recently posted events in %communities% you have joined.');
    }

    public function component(): string
    {
        return 'gadget.recent-community-event-comment';
    }

    public function feature(): ?Feature
    {
        return Feature::CommunityEvent;
    }
}
