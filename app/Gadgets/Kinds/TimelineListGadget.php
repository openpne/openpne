<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Gadgets\GadgetConfigField;
use App\Gadgets\GadgetKind;

/**
 * Shared base for the OpenPNE 3 opTimelinePlugin home timeline gadgets (timelineAll / timelineFriend):
 * a server-rendered slice of the timeline whose only config is how many posts to show. Each concrete
 * kind keeps its own OpenPNE 3 wrapper id (homeAllTimeline_ / homeFriendTimeline_).
 */
abstract class TimelineListGadget extends GadgetKind
{
    public function contexts(): array
    {
        return ['home'];
    }

    public function configFields(string $context): array
    {
        $choices = array_combine([5, 10, 15, 20], ['5', '10', '15', '20']);

        return [
            new GadgetConfigField('limit', ['ja' => '最大表示件数', 'en' => 'Maximum entries'], 'select', GadgetConfigField::INT, true, 20, $choices),
        ];
    }
}
