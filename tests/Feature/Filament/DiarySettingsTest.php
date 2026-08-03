<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\DiarySettings;
use App\Models\AdminUser;
use App\Support\SnsSettingKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The diary settings editor. DB-authoritative; a fresh install with no row falls back to OpenPNE 3's
 * default (web-public entries on), so the path that matters here is an administrator turning it off.
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
}
