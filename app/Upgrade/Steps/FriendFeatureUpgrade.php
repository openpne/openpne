<?php

namespace App\Upgrade\Steps;

use App\Support\Feature;
use App\Upgrade\Column;

/**
 * OpenPNE 3 `sns_config.enable_friend_link` → the OpenPNE 4 friend flag; OpenPNE 3 kept this one
 * feature in sns_config with the `plugin` table's semantics. A step of its own rather than a
 * SnsSettingUpgrade key: that step copies the value as stored, and only carrying the disabled row
 * keeps "absent means enabled" on both sides.
 */
class FriendFeatureUpgrade extends FeatureFlagUpgrade
{
    protected string $source = 'sns_config';

    public function filter(): ?string
    {
        return "`name` = 'enable_friend_link' AND `value` = '0'";
    }

    public function filterColumns(): array
    {
        return ['name', 'value'];
    }

    protected function keyColumn(): Column
    {
        return Column::expr(sprintf("'%s'", Feature::Friend->settingKey()->value));
    }

    protected function featureKeys(): array
    {
        return [Feature::Friend->settingKey()];
    }
}
