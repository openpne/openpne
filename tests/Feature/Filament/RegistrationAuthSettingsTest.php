<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Captcha\Captcha;
use App\Features\Auth\RegistrationMode;
use App\Filament\Pages\RegistrationAuthSettings;
use App\Models\AdminUser;
use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The registration/authentication settings editor. These settings are DB-authoritative: saving takes
 * effect immediately (the CAPTCHA wrapper re-reads the toggle rather than freezing it), and a fresh
 * install with no rows falls back to the fail-closed defaults (invite-only, CAPTCHA on).
 */
class RegistrationAuthSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_saving_registration_mode_takes_effect(): void
    {
        Livewire::test(RegistrationAuthSettings::class)
            ->fillForm(['registration_mode' => 'closed'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sns_settings', ['key' => 'registration_mode', 'value' => 'closed']);
        $this->assertSame(RegistrationMode::Closed, RegistrationMode::current());
    }

    public function test_saving_admin_only_mode_takes_effect(): void
    {
        Livewire::test(RegistrationAuthSettings::class)
            ->fillForm(['registration_mode' => 'admin_only'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sns_settings', ['key' => 'registration_mode', 'value' => 'admin_only']);
        $this->assertSame(RegistrationMode::AdminOnly, RegistrationMode::current());
    }

    public function test_toggling_captcha_takes_effect_on_the_bound_instance(): void
    {
        // The seeded baseline is off; resolve the singleton now so a frozen decision would stay off.
        $captcha = app(Captcha::class);
        $this->assertFalse($captcha->enabled());

        Livewire::test(RegistrationAuthSettings::class)
            ->fillForm(['captcha_enabled' => true])
            ->call('save')
            ->assertHasNoErrors();

        // The same resolved instance now reports enabled — the wrapper re-read the setting.
        $this->assertTrue($captcha->enabled());
        $this->assertDatabaseHas('sns_settings', ['key' => 'captcha_enabled', 'value' => '1']);
    }

    public function test_fail_closed_defaults_when_no_row_exists(): void
    {
        // Clear the permissive test baseline to simulate a fresh install with no stored rows.
        DB::table('sns_settings')->truncate();
        app(SnsSettingService::class)->clearCache();

        $this->assertSame(RegistrationMode::Invite, RegistrationMode::current());
        $this->assertTrue(app(Captcha::class)->enabled());
    }

    public function test_saved_values_round_trip_into_the_form(): void
    {
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'invite');
        $this->setSnsSetting(SnsSettingKey::CaptchaEnabled, true);

        Livewire::test(RegistrationAuthSettings::class)
            ->assertSet('data.registration_mode', 'invite')
            ->assertSet('data.captcha_enabled', true);
    }
}
