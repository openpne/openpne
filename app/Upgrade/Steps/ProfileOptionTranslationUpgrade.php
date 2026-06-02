<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `profile_option_translation` → OpenPNE 4 `profile_option_translations`
 * (localised option label per (id, lang); id is the profile_option id).
 */
class ProfileOptionTranslationUpgrade extends UpgradeStep
{
    protected string $source = 'profile_option_translation';

    protected string $target = 'profile_option_translations';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'value' => Column::source('value'),
            'lang' => Column::source('lang'),
        ];
    }
}
