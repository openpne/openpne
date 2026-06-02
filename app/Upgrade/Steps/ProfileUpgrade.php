<?php

namespace App\Upgrade\Steps;

use App\Models\Profile;
use App\Upgrade\Column;
use App\Upgrade\UpgradeStep;

/**
 * OpenPNE 3 `profile` → OpenPNE 4 `profiles` (profile field definitions).
 *
 * id is preserved (profile_options, member_profiles, and the translation tables reference
 * it). default_public_flag is normalised to 1-4: OpenPNE 3's preset form seeds 0, which is
 * not a valid public-flag, so it would break the visibility fallback.
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
            'default_public_flag' => Column::expr(
                sprintf('CASE WHEN `default_public_flag` IN (1, 2, 3, 4) THEN `default_public_flag` ELSE %d END', Profile::PUBLIC_FLAG_SNS),
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
