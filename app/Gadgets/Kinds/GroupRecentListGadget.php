<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Gadgets\GadgetConfigField;
use App\Gadgets\GadgetKind;

/**
 * Shared base for the OpenPNE 3 opCommunityTopicPlugin recent-list gadgets
 * (recentCommunityTopicComment{,Sns} / recentCommunityEventComment{,Sns}): a "recently active
 * community topics/events" box whose only config is how many rows to show. Placed on the home page
 * with the OpenPNE 3 homeRecentList_{id} DOM id.
 */
abstract class GroupRecentListGadget extends GadgetKind
{
    public function contexts(): array
    {
        return ['home'];
    }

    public function configFields(string $context): array
    {
        $choices = array_combine([1, 3, 5, 7, 10], ['1', '3', '5', '7', '10']);

        return [
            new GadgetConfigField('col', ['ja' => '表示件数', 'en' => 'Entries to show'], 'select', GadgetConfigField::INT, true, 5, $choices),
        ];
    }

    public function partId(int $gadgetId): ?string
    {
        return 'homeRecentList_'.$gadgetId;
    }
}
