<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\FeatureSettings;
use App\Models\AdminUser;
use App\Models\Member;
use App\Support\Feature;
use App\Support\SettingGroup;
use App\Support\SnsSettingKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The feature-toggle editor. DB-authoritative; a fresh install with no row runs every unit, and the
 * page stores its whole group on save (docs/internals/feature-toggles.md).
 */
class FeatureSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_page_renders_a_toggle_for_every_unit(): void
    {
        $component = Livewire::test(FeatureSettings::class)->assertOk();

        foreach (Feature::cases() as $feature) {
            $component->assertFormFieldExists($feature->settingKey()->value);
        }
    }

    public function test_every_unit_shows_as_enabled_when_no_row_exists(): void
    {
        $component = Livewire::test(FeatureSettings::class);

        foreach (Feature::cases() as $feature) {
            $component->assertSet("data.{$feature->settingKey()->value}", true);
        }
    }

    public function test_a_stored_value_round_trips_into_the_form(): void
    {
        $this->setSnsSetting(SnsSettingKey::FeatureMessageEnabled, false);

        Livewire::test(FeatureSettings::class)
            ->assertSet('data.feature_message_enabled', false)
            ->assertSet('data.feature_diary_enabled', true);
    }

    public function test_saving_writes_a_row_for_every_key(): void
    {
        Livewire::test(FeatureSettings::class)
            ->fillForm(['feature_diary_enabled' => false])
            ->call('save')
            ->assertHasNoErrors();

        foreach (SnsSettingKey::inGroup(SettingGroup::Features) as $key) {
            $this->assertDatabaseHas('sns_settings', [
                'key' => $key->value,
                'value' => $key === SnsSettingKey::FeatureDiaryEnabled ? '0' : '1',
            ]);
        }
    }

    public function test_switching_a_unit_off_takes_effect_immediately(): void
    {
        // Read first, so the save has a populated settings cache to clear.
        $this->assertTrue(Feature::Diary->enabled());

        Livewire::test(FeatureSettings::class)
            ->fillForm(['feature_diary_enabled' => false])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(Feature::Diary->enabled());
        $this->assertTrue(Feature::Message->enabled());
    }

    public function test_switching_communities_off_takes_their_topics_and_events_with_them(): void
    {
        Livewire::test(FeatureSettings::class)
            ->fillForm(['feature_community_enabled' => false])
            ->call('save')
            ->assertHasNoErrors();

        // The topic/event rows stay '1'; the dependency resolves in the registry, not in storage.
        $this->assertDatabaseHas('sns_settings', ['key' => 'feature_community_topic_enabled', 'value' => '1']);
        $this->assertFalse(Feature::CommunityTopic->enabled());
        $this->assertFalse(Feature::CommunityEvent->enabled());
    }

    public function test_members_and_guests_cannot_reach_the_page(): void
    {
        $url = FeatureSettings::getUrl();

        $this->app['auth']->forgetGuards();
        $this->get($url)->assertRedirect('/admin/login');

        $this->actingAs(Member::factory()->create(), 'member')
            ->get($url)
            ->assertRedirect('/admin/login');
    }
}
