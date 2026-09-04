<?php

namespace App\Upgrade\Steps;

use App\Support\Feature;
use App\Support\SnsSettingKey;
use App\Upgrade\Column;

/**
 * OpenPNE 3 `plugin` (is_enabled) → the OpenPNE 4 feature flags in `sns_settings`; OpenPNE 3 wrote a
 * `plugin` row lazily, so only `is_enabled = 0` rows are copied (FeatureFlagUpgrade). The plugin
 * directory names live in pluginFeatures() alone, and GroupEventPluginFeatureUpgrade reads the same
 * opCommunityTopicPlugin row since OpenPNE 3 shipped topics and events in one plugin.
 */
class PluginFeatureUpgrade extends FeatureFlagUpgrade
{
    protected string $source = 'plugin';

    /** Shared with GroupEventPluginFeatureUpgrade, the second unit this one plugin carries. */
    public const COMMUNITY_TOPIC_PLUGIN = 'opCommunityTopicPlugin';

    public function filter(): ?string
    {
        $names = implode(', ', array_map(
            static fn (string $plugin): string => "'{$plugin}'",
            array_keys(self::pluginFeatures()),
        ));

        return "`name` IN ({$names}) AND `is_enabled` = 0";
    }

    public function filterColumns(): array
    {
        return ['name', 'is_enabled'];
    }

    public function gaps(): array
    {
        return [
            'id' => 'OpenPNE 3 plugin surrogate key; sns_settings is keyed by the setting name (`key`), not a numeric id.',
            'created_at' => 'When the plugin row was written, not part of the flag; sns_settings stores the value alone.',
            'updated_at' => 'When the plugin row was last written, not part of the flag; sns_settings stores the value alone.',
        ];
    }

    /**
     * OpenPNE 3 plugin directory name → the OpenPNE 4 unit whose flag its is_enabled becomes.
     *
     * @return array<string, Feature>
     */
    public static function pluginFeatures(): array
    {
        return [
            'opDiaryPlugin' => Feature::Diary,
            'opMessagePlugin' => Feature::DirectMessage,
            'opTimelinePlugin' => Feature::Timeline,
            self::COMMUNITY_TOPIC_PLUGIN => Feature::GroupTopic,
        ];
    }

    protected function keyColumn(): Column
    {
        $whens = array_map(
            static fn (string $plugin, Feature $feature): string => sprintf("WHEN '%s' THEN '%s'", $plugin, $feature->settingKey()->value),
            array_keys(self::pluginFeatures()),
            array_values(self::pluginFeatures()),
        );

        return Column::expr('CASE `name` '.implode(' ', $whens).' END', uses: ['name']);
    }

    protected function featureKeys(): array
    {
        return array_values(array_map(
            static fn (Feature $feature): SnsSettingKey => $feature->settingKey(),
            self::pluginFeatures(),
        ));
    }
}
