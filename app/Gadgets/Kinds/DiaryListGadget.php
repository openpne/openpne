<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

use App\Gadgets\GadgetConfigField;
use App\Gadgets\GadgetKind;

/**
 * Shared base for the OpenPNE 3 diary list gadgets (opDiaryPlugin diaryFriendList / diaryMyList /
 * diaryList / diaryMemberList): a "recently posted diaries" box whose only config is how many rows
 * to show. Placed on the home page with the OpenPNE 3 homeRecentList_{id} DOM id by default;
 * diaryMemberList overrides both for the profile page.
 */
abstract class DiaryListGadget extends GadgetKind
{
    public function contexts(): array
    {
        return ['home'];
    }

    public function configFields(string $context): array
    {
        $choices = array_combine([1, 3, 5, 7, 10], ['1', '3', '5', '7', '10']);

        return [
            new GadgetConfigField('max', ['ja' => '最大表示件数', 'en' => 'Maximum entries'], 'select', GadgetConfigField::INT, true, 5, $choices),
        ];
    }

    public function partId(int $gadgetId): ?string
    {
        return 'homeRecentList_'.$gadgetId;
    }
}
