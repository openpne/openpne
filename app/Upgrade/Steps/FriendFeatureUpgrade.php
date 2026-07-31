<?php

namespace App\Upgrade\Steps;

use App\Support\Feature;
use App\Upgrade\Column;

/**
 * OpenPNE 3 `sns_config.enable_friend_link` → the OpenPNE 4 friend flag. OpenPNE 3 kept this one
 * feature in sns_config rather than in `plugin`, but the semantics are the plugin table's.
 *
 * A step of its own rather than a key in SnsSettingUpgrade: that step copies whatever value the
 * source holds, so an OpenPNE 3 site with friends enabled — by an absent row or an explicit '1' —
 * would either lose "absent means enabled" or leave the verify count depending on which of the two
 * OpenPNE 3 wrote. Carrying only the disabled row keeps both sides lazy.
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
