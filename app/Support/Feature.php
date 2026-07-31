<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SnsSettingService;

/**
 * The closed registry of admin-togglable feature units, and the single source of truth for which
 * setting stores a unit's flag and which routes it owns. See docs/internals/feature-toggles.md.
 *
 * Switching a unit off is a gate, never a data operation: its rows, files and relationships stay,
 * and switching it back on restores the feature intact (OpenPNE 3 `plugin.is_enabled` parity).
 *
 * The case value is the feature vocabulary the surface resolver already uses (App\Support\SurfaceResolver),
 * the route-name prefix, and the URL segment — one word per unit, not three lists.
 */
enum Feature: string
{
    case Diary = 'diary';

    case Message = 'message';

    case Timeline = 'timeline';

    case Community = 'community';

    case CommunityTopic = 'communityTopic';

    case CommunityEvent = 'communityEvent';

    case Friend = 'friend';

    /** The `sns_settings` key holding this unit's flag. */
    public function settingKey(): SnsSettingKey
    {
        return match ($this) {
            self::Diary => SnsSettingKey::FeatureDiaryEnabled,
            self::Message => SnsSettingKey::FeatureMessageEnabled,
            self::Timeline => SnsSettingKey::FeatureTimelineEnabled,
            self::Community => SnsSettingKey::FeatureCommunityEnabled,
            self::CommunityTopic => SnsSettingKey::FeatureCommunityTopicEnabled,
            self::CommunityEvent => SnsSettingKey::FeatureCommunityEventEnabled,
            self::Friend => SnsSettingKey::FeatureFriendEnabled,
        };
    }

    /** The unit this one lives inside, or null when it stands alone. */
    public function parent(): ?self
    {
        return match ($this) {
            self::CommunityTopic, self::CommunityEvent => self::Community,
            default => null,
        };
    }

    /**
     * This unit's flag AND every ancestor's: a topic board is unreachable while communities are off,
     * whatever its own flag says, so the dependency is resolved here rather than at each call site.
     */
    public function enabled(): bool
    {
        $settings = app(SnsSettingService::class);

        for ($feature = $this; $feature !== null; $feature = $feature->parent()) {
            if (! $settings->get($feature->settingKey())) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every unit's resolved state (dependencies applied), keyed by case value.
     *
     * @return array<string, bool>
     */
    public static function enabledMap(): array
    {
        $map = [];
        foreach (self::cases() as $feature) {
            $map[$feature->value] = $feature->enabled();
        }

        return $map;
    }

    /**
     * Route-name prefixes this unit owns. Dot-terminated, so `community.` never claims a
     * `communityTopic.*` route.
     *
     * @return list<string>
     */
    public function routeNamePrefixes(): array
    {
        return [$this->value.'.'];
    }

    /** The unit owning a route name, or null when no unit does. */
    public static function owningRouteName(string $name): ?self
    {
        foreach (self::cases() as $feature) {
            foreach ($feature->routeNamePrefixes() as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    return $feature;
                }
            }
        }

        return null;
    }

    /** The unit's display name — the same string labels its admin toggle. */
    public function label(): string
    {
        return $this->settingKey()->label();
    }
}
