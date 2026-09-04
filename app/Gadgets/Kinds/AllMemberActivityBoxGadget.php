<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Gadgets\GadgetConfigField;
use App\Gadgets\GadgetKind;
use App\Support\Feature;

/**
 * OpenPNE 3 allMemberActivityBox; it shares the activityBox_ DOM id with activityBox (a common partial
 * built the id), so custom CSS targets one selector.
 */
class AllMemberActivityBoxGadget extends GadgetKind
{
    public function name(): string
    {
        return 'allMemberActivityBox';
    }

    public function label(): string
    {
        return __('All Member Activity Box');
    }

    public function description(): string
    {
        return __('Recent %activity% posts from every member across the SNS.');
    }

    public function component(): string
    {
        return 'gadget.all-member-activity-box';
    }

    public function contexts(): array
    {
        return ['home'];
    }

    public function configFields(string $context): array
    {
        $oneToTen = array_combine(range(1, 10), array_map('strval', range(1, 10)));

        return [
            new GadgetConfigField('row', ['ja' => '表示する行', 'en' => 'Rows to show'], 'select', GadgetConfigField::INT, true, 5, $oneToTen),
            new GadgetConfigField('is_viewable_activity_form', ['ja' => '投稿フォームを表示', 'en' => 'Show post form'], 'radio', GadgetConfigField::INT, true, 1, [0 => 'No', 1 => 'Yes']),
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
