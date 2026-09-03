<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Support\Feature;

/** Recent events across every public community (OpenPNE 3 recentCommunityEventCommentSns). */
class RecentGroupEventCommentSnsGadget extends GroupRecentListGadget
{
    public function name(): string
    {
        return 'recentGroupEventCommentSns';
    }

    public function label(): string
    {
        return __('Recent %Community% Event Comment Sns');
    }

    public function description(): string
    {
        return __('Recently posted events in public %communities%.');
    }

    public function component(): string
    {
        return 'gadget.recent-group-event-comment-sns';
    }

    public function feature(): ?Feature
    {
        return Feature::GroupEvent;
    }
}
