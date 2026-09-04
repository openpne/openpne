<?php

namespace Tests\Feature\Surface;

use App\Models\Member;
use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use App\Support\SurfaceMode;
use App\Support\SurfaceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Inertia;
use Tests\TestCase;

/**
 * The test env pins OPENPNE_SURFACE_MODE=classic_default (phpunit.xml), so an install with no row
 * resolves to classic_default here.
 */
class SurfaceModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_enum_derives_availability_and_default_surface(): void
    {
        $this->assertFalse(SurfaceMode::ModernOnly->classicAvailable());
        $this->assertTrue(SurfaceMode::ClassicDefault->classicAvailable());
        $this->assertTrue(SurfaceMode::ModernDefault->classicAvailable());

        $this->assertSame('classic', SurfaceMode::ClassicDefault->defaultSurface()->value);
        $this->assertSame('modern', SurfaceMode::ModernDefault->defaultSurface()->value);
        $this->assertSame('modern', SurfaceMode::ModernOnly->defaultSurface()->value);
    }

    public function test_absent_row_resolves_to_the_config_default(): void
    {
        // No sns_settings row; the test env config default is classic_default.
        $this->assertSame(SurfaceMode::ClassicDefault, app(SnsSettingService::class)->get(SnsSettingKey::SurfaceMode));
        $this->assertTrue(SurfaceResolver::classicAvailable());
    }

    public function test_modern_only_disables_classic(): void
    {
        config(['openpne.surface_mode' => 'modern_only']);

        $this->assertFalse(SurfaceResolver::classicAvailable());
    }

    public function test_codec_round_trips_and_a_bad_value_falls_back_to_the_default(): void
    {
        $key = SnsSettingKey::SurfaceMode;

        $this->assertSame('modern_default', $key->encode(SurfaceMode::ModernDefault));
        $this->assertSame(SurfaceMode::ModernDefault, $key->decode('modern_default'));
        // An unknown stored value must not throw; it fails safe to the install default (classic_default here).
        $this->assertSame(SurfaceMode::ClassicDefault, $key->decode('nonsense'));
    }

    public function test_command_shows_the_current_mode(): void
    {
        $this->artisan('openpne:surface-mode')
            ->expectsOutputToContain('classic_default')
            ->assertSuccessful();
    }

    public function test_command_sets_the_mode_and_a_stored_row_wins_over_config(): void
    {
        $this->artisan('openpne:surface-mode', ['mode' => 'modern_only'])->assertSuccessful();

        $this->assertDatabaseHas('sns_settings', ['key' => 'surface_mode', 'value' => 'modern_only']);
        // The stored row is authoritative over the config default (still classic_default here).
        $this->assertFalse(SurfaceResolver::classicAvailable());
    }

    public function test_command_rejects_an_invalid_mode_without_writing(): void
    {
        $this->artisan('openpne:surface-mode', ['mode' => 'bogus'])->assertFailed();

        $this->assertDatabaseMissing('sns_settings', ['key' => 'surface_mode']);
    }

    /**
     * The Inertia client rejects a Classic Blade body, so the URL that resolves to Classic on a full
     * load must still answer Modern to an X-Inertia request.
     */
    public function test_an_inertia_navigation_is_always_served_modern(): void
    {
        // classic_default (the test env default), member with no surface preference.
        $member = Member::factory()->create();

        // Full page load: resolves to Classic.
        $this->actingAs($member)->get('/friend/list')
            ->assertOk()
            ->assertSee('id="page_friend_list"', false);

        // The same canonical URL as an Inertia request: must be Modern.
        $headers = ['X-Inertia' => 'true'];
        if (($version = Inertia::getVersion()) !== null) {
            $headers['X-Inertia-Version'] = $version;
        }
        $this->actingAs($member)->get('/friend/list', $headers)
            ->assertOk()
            ->assertJsonPath('component', 'friend/list');
    }
}
