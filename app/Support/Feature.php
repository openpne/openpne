<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SnsSettingService;

/**
 * The closed registry of admin-togglable feature units (docs/internals/feature-toggles.md). Switching
 * a unit off is a gate, never a data operation: its rows stay and switching it back on restores it
 * intact (OpenPNE 3 `plugin.is_enabled`).
 */
enum Feature: string
{
    case Diary = 'diary';

    case DirectMessage = 'directMessage';

    case Timeline = 'timeline';

    case Group = 'group';

    case GroupTopic = 'groupTopic';

    case GroupEvent = 'groupEvent';

    case GroupTalk = 'groupTalk';

    case Friend = 'friend';

    /** The MCP endpoint — not a screen, so it owns no route name and no navigation. */
    case Mcp = 'mcp';

    /** The `sns_settings` key holding this unit's flag. */
    public function settingKey(): SnsSettingKey
    {
        return match ($this) {
            self::Diary => SnsSettingKey::FeatureDiaryEnabled,
            self::DirectMessage => SnsSettingKey::FeatureDirectMessageEnabled,
            self::Timeline => SnsSettingKey::FeatureTimelineEnabled,
            self::Group => SnsSettingKey::FeatureGroupEnabled,
            self::GroupTopic => SnsSettingKey::FeatureGroupTopicEnabled,
            self::GroupEvent => SnsSettingKey::FeatureGroupEventEnabled,
            self::GroupTalk => SnsSettingKey::FeatureGroupTalkEnabled,
            self::Friend => SnsSettingKey::FeatureFriendEnabled,
            self::Mcp => SnsSettingKey::FeatureMcpEnabled,
        };
    }

    /** The unit this one lives inside, or null when it stands alone. */
    public function parent(): ?self
    {
        return match ($this) {
            self::GroupTopic, self::GroupEvent, self::GroupTalk => self::Group,
            default => null,
        };
    }

    /**
     * Whether the unit has Modern screens only, so it is off wherever Modern is not served; interim
     * until group talk has a Classic screen (docs/internals/feature-toggles.md, "Dependencies").
     */
    public function requiresModern(): bool
    {
        return $this === self::GroupTalk;
    }

    /**
     * This unit's flag AND every ancestor's: a topic board is unreachable while groups are off,
     * whatever its own flag says, so the dependency is resolved here rather than at each call site.
     */
    public function enabled(): bool
    {
        if ($this->requiresModern() && ! SurfaceResolver::modernAvailable()) {
            return false;
        }

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
     * Dot-terminated, so a prefix claims whole name segments and never a route that merely begins
     * with the same letters.
     *
     * @return list<string>
     */
    public function routeNamePrefixes(): array
    {
        return match ($this) {
            // The DM routes and URLs keep the OpenPNE 3 `message` word until they are redesigned.
            self::DirectMessage => ['message.'],
            self::Diary => ['diary.'],
            self::Timeline => ['timeline.'],
            self::Group => ['group.'],
            self::GroupTopic => ['group.topics.'],
            self::GroupEvent => ['group.events.'],
            self::GroupTalk => ['group.talk.'],
            self::Friend => ['friend.'],
            // The `/mcp` routes come from the package unnamed and carry the gate by hand (routes/ai.php);
            // the named ones are the OAuth sign-in surface.
            self::Mcp => ['passport.', 'mcp.oauth.'],
        };
    }

    /**
     * The unit owning a route name, or null when no unit does. The longest matching prefix wins,
     * so `group.topics.show` belongs to the board rather than to the group it nests under.
     */
    public static function owningRouteName(string $name): ?self
    {
        $owner = null;
        $matched = 0;

        foreach (self::cases() as $feature) {
            foreach ($feature->routeNamePrefixes() as $prefix) {
                if (str_starts_with($name, $prefix) && strlen($prefix) > $matched) {
                    $owner = $feature;
                    $matched = strlen($prefix);
                }
            }
        }

        return $owner;
    }

    /** The unit's display name — the same string labels its admin toggle. */
    public function label(): string
    {
        return $this->settingKey()->label();
    }
}
