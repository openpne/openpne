<?php

namespace Tests\Unit\Support;

use App\Support\Feature;
use App\Support\SettingGroup;
use App\Support\SnsSettingKey;
use PHPUnit\Framework\TestCase;

/**
 * The feature registry's static shape: which setting stores a unit's flag, which unit contains
 * which, which route names a unit owns, and how a stored flag decodes. The resolved state (and its
 * dependency chain) is exercised against the store in Tests\Feature\Support\FeatureStateTest.
 */
class FeatureTest extends TestCase
{
    public function test_every_unit_maps_to_a_setting_in_the_features_group(): void
    {
        $keys = [];

        foreach (Feature::cases() as $feature) {
            $key = $feature->settingKey();
            $this->assertSame(SettingGroup::Features, $key->group(), "{$feature->value} is stored outside the Features group");
            $keys[] = $key->value;
        }

        $this->assertSame($keys, array_unique($keys), 'two units share one setting key');
        $this->assertSame(
            $keys,
            array_map(fn (SnsSettingKey $key): string => $key->value, SnsSettingKey::inGroup(SettingGroup::Features)),
            'the Features group holds a key no unit owns (or is missing one)',
        );
    }

    public function test_the_board_and_the_calendar_live_inside_communities(): void
    {
        $this->assertSame(Feature::Group, Feature::CommunityTopic->parent());
        $this->assertSame(Feature::Group, Feature::CommunityEvent->parent());

        foreach ([Feature::Diary, Feature::DirectMessage, Feature::Timeline, Feature::Group, Feature::Friend] as $feature) {
            $this->assertNull($feature->parent(), "{$feature->value} unexpectedly depends on another unit");
        }
    }

    public function test_route_name_prefixes_are_dot_terminated(): void
    {
        $this->assertSame(['diary.'], Feature::Diary->routeNamePrefixes());
        $this->assertSame(['group.'], Feature::Group->routeNamePrefixes());
        $this->assertSame(['communityTopic.'], Feature::CommunityTopic->routeNamePrefixes());
    }

    public function test_a_route_name_resolves_to_the_unit_that_owns_it(): void
    {
        $this->assertSame(Feature::Group, Feature::owningRouteName('group.show'));
        $this->assertSame(Feature::Group, Feature::owningRouteName('group.recent'));
        // Dot-terminated prefixes: the board is its own unit, never captured by `community.`.
        $this->assertSame(Feature::CommunityTopic, Feature::owningRouteName('communityTopic.show'));
        $this->assertSame(Feature::CommunityEvent, Feature::owningRouteName('communityEvent.comment.store'));
        $this->assertSame(Feature::DirectMessage, Feature::owningRouteName('message.index_compat'));

        $this->assertNull(Feature::owningRouteName('member.profile.show'));
        $this->assertNull(Feature::owningRouteName('community'));
        $this->assertNull(Feature::owningRouteName('block.list'));
    }

    public function test_a_stored_flag_disables_only_on_an_explicit_zero(): void
    {
        // Fail-open: an availability switch must not black out a module on a malformed value.
        foreach (Feature::cases() as $feature) {
            $key = $feature->settingKey();

            $this->assertTrue($key->decode(null), "{$key->value}: an absent row must mean enabled");
            $this->assertTrue($key->default());
            $this->assertTrue($key->decode('1'));
            $this->assertTrue($key->decode(''));
            $this->assertTrue($key->decode('garbage'));
            $this->assertFalse($key->decode('0'));
        }
    }

    public function test_a_flag_round_trips_through_the_codec(): void
    {
        $key = Feature::Diary->settingKey();

        $this->assertSame('0', $key->encode($key->coerce(false)));
        $this->assertSame('1', $key->encode($key->coerce(true)));
        $this->assertFalse($key->decode($key->encode($key->coerce('0'))));
    }

    public function test_no_unit_upgrades_as_a_plain_sns_config_copy(): void
    {
        // OpenPNE 3 held plugin availability in `plugin`, and enable_friend_link upgrades through a
        // dedicated step that writes only disabled rows — so none of these is a 1:1 column copy.
        foreach (Feature::cases() as $feature) {
            $this->assertNull($feature->settingKey()->op3SourceName());
            $this->assertFalse($feature->settingKey()->isMigratedFromOp3());
        }
    }
}
