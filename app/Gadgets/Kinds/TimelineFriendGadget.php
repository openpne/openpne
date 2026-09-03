<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Support\Feature;

/** The viewer and their friends' recent timeline (OpenPNE 3 timelineFriend). */
class TimelineFriendGadget extends TimelineListGadget
{
    public function name(): string
    {
        return 'timelineFriend';
    }

    public function label(): string
    {
        return __('%Activity% %Friend%');
    }

    public function description(): string
    {
        return __('Recent %activity% posts from you and your %friends%.');
    }

    public function component(): string
    {
        return 'gadget.timeline-friend';
    }

    /** The friend lens is this kind's whole content, so it goes when friends go. */
    public function dependsOn(string $context): ?Feature
    {
        return Feature::Friend;
    }

    public function partId(int $gadgetId): ?string
    {
        return 'homeFriendTimeline_'.$gadgetId;
    }
}
