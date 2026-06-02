<?php

namespace App\Upgrade\Steps;

use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `profile_option` → OpenPNE 4 `profile_options` (choices for custom
 * select/radio/checkbox fields). id is preserved (member_profiles and the option
 * translation table reference it). Preset fields have no options here.
 */
class ProfileOptionUpgrade extends UpgradeStep
{
    protected string $source = 'profile_option';

    protected string $target = 'profile_options';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'profile_id' => Column::source('profile_id'),
            'sort_order' => Column::source('sort_order'),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }
}
