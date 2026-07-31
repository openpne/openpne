<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Gadgets\GadgetConfigField;
use App\Gadgets\GadgetKind;
use App\Support\Feature;

/**
 * OpenPNE 3 activityBox: a server-rendered activity list placed on both the home and a profile. The
 * one kind behaves differently by context — on the home it shows the viewer + friends feed, on a
 * profile the owner's timeline — so the component branches on the render context. Both contexts share
 * OpenPNE 3's activityBox_ DOM id, so custom CSS targets a single selector.
 */
class ActivityBoxGadget extends GadgetKind
{
    public function name(): string
    {
        return 'activityBox';
    }

    public function description(): string
    {
        return __("Recent %activity% posts from you and your %friends% (or a member's own on a profile).");
    }

    public function component(): string
    {
        return 'gadget.activity-box';
    }

    public function contexts(): array
    {
        return ['home', 'profile'];
    }

    public function configFields(string $context): array
    {
        $oneToTen = array_combine(range(1, 10), array_map('strval', range(1, 10)));

        return [
            new GadgetConfigField('row', ['ja' => '表示する行', 'en' => 'Rows to show'], 'select', GadgetConfigField::INT, true, 5, $oneToTen),
        ];
    }

    public function feature(): ?Feature
    {
        return Feature::Timeline;
    }

    public function partId(int $gadgetId): ?string
    {
        return 'activityBox_'.$gadgetId;
    }
}
