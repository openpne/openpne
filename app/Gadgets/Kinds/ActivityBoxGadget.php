<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Gadgets\GadgetConfigField;
use App\Gadgets\GadgetKind;
use App\Support\Feature;

/** OpenPNE 3 activityBox; both contexts share its activityBox_ DOM id, so custom CSS targets one selector. */
class ActivityBoxGadget extends GadgetKind
{
    public function name(): string
    {
        return 'activityBox';
    }

    public function label(): string
    {
        return __('Activity Box');
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

    /** Only the home box is the friend feed; a profile shows the owner's own timeline and stays. */
    public function dependsOn(string $context): ?Feature
    {
        return $context === 'home' ? Feature::Friend : null;
    }

    public function partId(int $gadgetId): ?string
    {
        return 'activityBox_'.$gadgetId;
    }
}
