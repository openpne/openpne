<?php

namespace App\Upgrade\Steps;

use App\Support\Visibility;
use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `profile` → OpenPNE 4 `profiles` (profile field definitions).
 *
 * id is preserved (profile_options, member_profiles, and the translation tables reference
 * it). OpenPNE 3's public_flag scale maps to App\Support\Visibility for default_visibility
 * (web=4→Open, friend=2→Friends, private=3→Private, SNS=1 and the invalid 0 default→Members).
 */
class ProfileUpgrade extends UpgradeStep
{
    protected string $source = 'profile';

    protected string $target = 'profiles';

    public function columns(): array
    {
        return [
            'id' => Column::source('id'),
            'name' => Column::source('name'),
            'is_required' => Column::source('is_required'),
            'is_unique' => Column::source('is_unique'),
            'is_edit_public_flag' => Column::source('is_edit_public_flag'),
            'default_visibility' => Column::expr(
                sprintf(
                    'CASE `default_public_flag` WHEN 4 THEN %d WHEN 2 THEN %d WHEN 3 THEN %d ELSE %d END',
                    Visibility::Open->value,
                    Visibility::Friends->value,
                    Visibility::Private->value,
                    Visibility::Members->value,
                ),
                uses: ['default_public_flag'],
            ),
            'form_type' => Column::source('form_type'),
            'value_type' => Column::source('value_type'),
            'is_disp_regist' => Column::source('is_disp_regist'),
            'is_disp_config' => Column::source('is_disp_config'),
            'is_disp_search' => Column::source('is_disp_search'),
            'is_public_web' => Column::source('is_public_web'),
            'value_regexp' => Column::source('value_regexp'),
            'value_min' => Column::source('value_min'),
            'value_max' => Column::source('value_max'),
            'sort_order' => Column::source('sort_order'),
            'created_at' => Column::source('created_at'),
            'updated_at' => Column::source('updated_at'),
        ];
    }
}
