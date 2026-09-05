<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Features\Diary\DiarySearch;
use App\Features\Diary\DiaryVisibility;
use App\Filament\Pages\DiarySettings;
use App\Models\AdminUser;
use App\Support\SnsSettingKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A fresh install with no row falls back to OpenPNE 3's default (web-public entries on), so the path
 * asserted here is an administrator turning it off.
 */
class DiarySettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_disabling_web_public_entries_takes_effect(): void
    {
        Livewire::test(DiarySettings::class)
            ->fillForm(['diary_allow_web_public' => false])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sns_settings', ['key' => 'diary_allow_web_public', 'value' => '0']);
    }

    public function test_saved_value_round_trips_into_the_form(): void
    {
        $this->setSnsSetting(SnsSettingKey::DiaryAllowWebPublic, false);

        Livewire::test(DiarySettings::class)
            ->assertSet('data.diary_allow_web_public', false);
    }

    public function test_defaults_on_when_no_row_exists(): void
    {
        Livewire::test(DiarySettings::class)
            ->assertSet('data.diary_allow_web_public', true);
    }

    public function test_the_search_settings_round_trip(): void
    {
        Livewire::test(DiarySettings::class)
            ->assertSet('data.diary_search_enabled', true)
            ->assertSet('data.diary_search_period_enabled', false)
            ->assertSet('data.diary_search_period_days', 30)
            ->fillForm(['diary_search_enabled' => false, 'diary_search_period_enabled' => true, 'diary_search_period_days' => 7])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sns_settings', ['key' => 'diary_search_enabled', 'value' => '0']);
        $this->assertDatabaseHas('sns_settings', ['key' => 'diary_search_period_enabled', 'value' => '1']);
        $this->assertDatabaseHas('sns_settings', ['key' => 'diary_search_period_days', 'value' => '7']);
        $this->assertFalse(DiarySearch::enabled());

        Livewire::test(DiarySettings::class)
            ->assertSet('data.diary_search_enabled', false)
            ->assertSet('data.diary_search_period_enabled', true)
            ->assertSet('data.diary_search_period_days', 7);
    }

    public function test_the_search_period_days_are_validated_before_the_save(): void
    {
        foreach (['abc', -1, 36501] as $days) {
            Livewire::test(DiarySettings::class)
                ->fillForm(['diary_search_period_days' => $days])
                ->call('save')
                ->assertHasErrors(['data.diary_search_period_days']);
        }

        $this->assertDatabaseMissing('sns_settings', ['key' => 'diary_search_period_days']);
    }

    public function test_saving_takes_effect_on_a_gate_that_already_read_the_setting(): void
    {
        // Prime the settings cache first: without save()'s clearCache() the gate would keep serving
        // web-public diaries for the cache TTL after the administrator switched them off.
        $this->assertTrue(DiaryVisibility::allowsWebPublic());

        Livewire::test(DiarySettings::class)
            ->fillForm(['diary_allow_web_public' => false])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(DiaryVisibility::allowsWebPublic());
    }
}
