<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Gadgets\GadgetKind;
use App\Support\Feature;

/**
 * A profile owner's recent timeline (OpenPNE 3 timelineProfile). Profile-only and unconfigurable —
 * OpenPNE 3 hard-coded 20 posts on the JS side, so there is nothing to configure.
 */
class TimelineProfileGadget extends GadgetKind
{
    public function name(): string
    {
        return 'timelineProfile';
    }

    public function description(): string
    {
        return __("The member's recent %activity% posts.");
    }

    public function component(): string
    {
        return 'gadget.timeline-profile';
    }

    public function contexts(): array
    {
        return ['profile'];
    }

    public function feature(): ?Feature
    {
        return Feature::Timeline;
    }

    public function partId(int $gadgetId): ?string
    {
        return 'profileTimeline_'.$gadgetId;
    }
}
