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
        $this->assertSame(0, DB::table('sns_settings')->where('key', 'like', 'feature_%')->count());

        foreach (Feature::cases() as $feature) {
            $this->assertTrue($feature->enabled(), "{$feature->value} is off on a fresh install");
        }
    }

    public function test_a_disabled_community_takes_the_board_and_the_calendar_with_it(): void
    {
        $this->setSnsSetting(Feature::Group->settingKey(), false);
        // Explicitly on, and still unreachable: the dependency wins over the unit's own row.
        $this->setSnsSetting(Feature::CommunityTopic->settingKey(), true);
        $this->setSnsSetting(Feature::CommunityEvent->settingKey(), true);

        $this->assertFalse(Feature::Group->enabled());
        $this->assertFalse(Feature::CommunityTopic->enabled());
        $this->assertFalse(Feature::CommunityEvent->enabled());
        $this->assertTrue(Feature::Diary->enabled());
    }

    public function test_disabling_a_contained_unit_leaves_its_siblings_alone(): void
    {
        $this->setSnsSetting(Feature::CommunityTopic->settingKey(), false);

        $this->assertFalse(Feature::CommunityTopic->enabled());
        $this->assertTrue(Feature::Group->enabled());
        $this->assertTrue(Feature::CommunityEvent->enabled());
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
            'communityTopic' => false,
            'communityEvent' => false,
            'friend' => true,
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
