<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\MemberPrivacySettings;
use App\Models\AdminUser;
use App\Support\SnsSettingKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The member-privacy settings editor. DB-authoritative; a fresh install with no row falls back to the
 * fail-closed default (web-public age off).
 */
class MemberPrivacySettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_enabling_web_public_age_takes_effect(): void
    {
        Livewire::test(MemberPrivacySettings::class)
            ->fillForm(['allow_web_public_age' => true])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sns_settings', ['key' => 'allow_web_public_age', 'value' => '1']);
    }

    public function test_saved_value_round_trips_into_the_form(): void
    {
        $this->setSnsSetting(SnsSettingKey::AllowWebPublicAge, true);

        Livewire::test(MemberPrivacySettings::class)
            ->assertSet('data.allow_web_public_age', true);
    }

    public function test_defaults_off_when_no_row_exists(): void
    {
        Livewire::test(MemberPrivacySettings::class)
            ->assertSet('data.allow_web_public_age', false);
    }
}
