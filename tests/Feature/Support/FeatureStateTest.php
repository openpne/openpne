<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Services\SnsSettingService;
use App\Support\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The resolved state of a feature unit: absent rows mean enabled, a stored '0' disables, and a
 * disabled container takes its contained units with it. The static registry (keys, prefixes, codec)
 * is covered in Tests\Unit\Support\FeatureTest.
 */
class FeatureStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_install_runs_every_unit(): void
    {
        // A fresh migration writes no feature rows at all; every unit resolves to its default.
        $this->assertSame(
            [],
            DB::table('sns_settings')->where('key', 'like', 'feature_%')->pluck('key')->all(),
        );

        foreach (Feature::cases() as $feature) {
            $this->assertTrue($feature->enabled(), "{$feature->value} is off on a fresh install");
        }
    }

    public function test_group_talk_follows_its_parent_like_the_other_group_units(): void
    {
        $this->assertTrue(Feature::GroupTalk->enabled());

        // Still contained by its parent, like the other group units.
        $this->setSnsSetting(Feature::Group->settingKey(), false);
        $this->assertFalse(Feature::GroupTalk->enabled());
    }

    public function test_a_disabled_community_takes_the_board_and_the_calendar_with_it(): void
    {
        $this->setSnsSetting(Feature::Group->settingKey(), false);
        // Explicitly on, and still unreachable: the dependency wins over the unit's own row.
        $this->setSnsSetting(Feature::GroupTopic->settingKey(), true);
        $this->setSnsSetting(Feature::GroupEvent->settingKey(), true);

        $this->assertFalse(Feature::Group->enabled());
        $this->assertFalse(Feature::GroupTopic->enabled());
        $this->assertFalse(Feature::GroupEvent->enabled());
        $this->assertTrue(Feature::Diary->enabled());
    }

    public function test_disabling_a_contained_unit_leaves_its_siblings_alone(): void
    {
        $this->setSnsSetting(Feature::GroupTopic->settingKey(), false);

        $this->assertFalse(Feature::GroupTopic->enabled());
        $this->assertTrue(Feature::Group->enabled());
        $this->assertTrue(Feature::GroupEvent->enabled());
    }

    public function test_the_enabled_map_reports_every_unit_with_dependencies_applied(): void
    {
        $this->setSnsSetting(Feature::Group->settingKey(), false);
        $this->setSnsSetting(Feature::DirectMessage->settingKey(), false);

        $this->assertSame([
            'diary' => true,
            'directMessage' => false,
            'timeline' => true,
            'group' => false,
            'groupTopic' => false,
            'groupEvent' => false,
            'groupTalk' => false,
            'friend' => true,
            'mcp' => true,
        ], Feature::enabledMap());
    }

    public function test_a_toggle_written_after_a_read_takes_effect_once_the_cache_is_cleared(): void
    {
        $this->assertTrue(Feature::Diary->enabled());

        DB::table('sns_settings')->updateOrInsert(['key' => Feature::Diary->settingKey()->value], ['value' => '0']);
        $this->assertTrue(Feature::Diary->enabled(), 'the core settings map is cached for the request');

        app(SnsSettingService::class)->clearCache();
        $this->assertFalse(Feature::Diary->enabled());
    }

    public function test_a_unit_is_labelled_by_its_setting(): void
    {
        foreach (Feature::cases() as $feature) {
            $this->assertSame($feature->settingKey()->label(), $feature->label());
            $this->assertNotSame('', $feature->label());
        }
    }
}
