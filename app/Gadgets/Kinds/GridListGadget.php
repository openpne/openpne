<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Gadgets\GadgetConfigField;
use App\Gadgets\GadgetKind;

/**
 * Shared base for the member/community grid lists (OpenPNE 3 friendListBox / communityJoinListBox):
 * a row × col thumbnail grid with a full / image-only / name-only display type. Both are members-only
 * and offered on the home and profile pages.
 */
abstract class GridListGadget extends GadgetKind
{
    public function contexts(): array
    {
        return ['home', 'profile'];
    }

    public function configFields(string $context): array
    {
        $oneToSix = array_combine(range(1, 6), array_map('strval', range(1, 6)));

        return [
            new GadgetConfigField('row', ['ja' => '表示する行', 'en' => 'Rows'], 'select', GadgetConfigField::INT, true, 3, $oneToSix),
            new GadgetConfigField('col', ['ja' => '表示する列', 'en' => 'Columns'], 'select', GadgetConfigField::INT, true, 3, $oneToSix),
            new GadgetConfigField('type', ['ja' => '表示タイプ', 'en' => 'Display type'], 'radio', GadgetConfigField::TEXT, true, 'full', [
                'full' => 'Image and name',
                'only_image' => 'Image only',
                'only_name' => 'Name only',
            ]),
        ];
    }
}
