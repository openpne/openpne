<?php

namespace App\Upgrade\Steps;

use App\Support\Visibility;
use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `profile` → OpenPNE 4 `profiles`. default_public_flag maps onto Visibility, with SNS=1
 * and the invalid 0 default falling to Members.
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
            // OpenPNE 3 presets use form_type 'text' for single-line input while custom
            // fields use 'input'; fold them into one 'input' so OpenPNE 4 has a single type.
            'form_type' => Column::expr("CASE WHEN `form_type` = 'text' THEN 'input' ELSE `form_type` END", uses: ['form_type']),
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
