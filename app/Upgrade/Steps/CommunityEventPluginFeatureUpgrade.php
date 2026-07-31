<?php

namespace App\Upgrade\Steps;

use App\Support\Feature;
use App\Upgrade\Column;

/**
 * The second unit OpenPNE 3's opCommunityTopicPlugin carries: disabling that one plugin took the
 * events feature down with the topic board, and OpenPNE 4 toggles the two separately, so one source
 * row becomes two `sns_settings` rows through two steps (the `member_relationship` steps do the same).
 * PluginFeatureUpgrade writes the topic row; this writes the event row.
 */
class CommunityEventPluginFeatureUpgrade extends FeatureFlagUpgrade
{
    protected string $source = 'plugin';

    public function filter(): ?string
    {
        return sprintf("`name` = '%s' AND `is_enabled` = 0", PluginFeatureUpgrade::COMMUNITY_TOPIC_PLUGIN);
    }

    public function filterColumns(): array
    {
        return ['name', 'is_enabled'];
    }

    protected function keyColumn(): Column
    {
        return Column::expr(sprintf("'%s'", Feature::CommunityEvent->settingKey()->value));
    }

    protected function featureKeys(): array
    {
        return [Feature::CommunityEvent->settingKey()];
    }
}
