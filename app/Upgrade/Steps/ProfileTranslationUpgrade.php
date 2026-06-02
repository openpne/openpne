<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `profile_translation` → OpenPNE 4 `profile_translations` (localised
 * caption/info per (id, lang); id is the profile id). No timestamps in either schema.
 */
class ProfileTranslationUpgrade extends UpgradeStep
{
    protected string $source = 'profile_translation';

    protected string $target = 'profile_translations';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'caption' => Column::source('caption'),
            'info' => Column::source('info'),
            'lang' => Column::source('lang'),
        ];
    }
}
