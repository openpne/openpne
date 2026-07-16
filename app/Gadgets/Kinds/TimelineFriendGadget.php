<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

/** The viewer and their friends' recent timeline (OpenPNE 3 timelineFriend). */
class TimelineFriendGadget extends TimelineListGadget
{
    public function name(): string
    {
        return 'timelineFriend';
    }

    public function description(): string
    {
        return __('Recent %activity% posts from you and your %friends%.');
    }

    public function component(): string
    {
        return 'gadget.timeline-friend';
    }

    public function partId(int $gadgetId): ?string
    {
        return 'homeFriendTimeline_'.$gadgetId;
    }
}
