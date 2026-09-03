<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

/** The whole SNS's recent timeline (OpenPNE 3 timelineAll). */
class TimelineAllGadget extends TimelineListGadget
{
    public function name(): string
    {
        return 'timelineAll';
    }

    public function label(): string
    {
        return __('%Activity% All');
    }

    public function description(): string
    {
        return __('Recent %activity% posts from every member.');
    }

    public function component(): string
    {
        return 'gadget.timeline-all';
    }

    public function partId(int $gadgetId): ?string
    {
        return 'homeAllTimeline_'.$gadgetId;
    }
}
