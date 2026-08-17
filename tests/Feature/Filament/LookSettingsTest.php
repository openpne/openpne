<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\LookSettings;
use App\Models\AdminUser;
use App\Services\SnsSettingService;
use App\Support\Look;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class LookSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_it_shows_the_stored_value(): void
    {
        DB::table('sns_settings')->updateOrInsert(['key' => SnsSettingKey::DefaultLook->value], ['value' => 'unified']);

        // The service hands back the typed Look; the radio holds the id it posts back, so this pins
        // the mapping between the two.
        Livewire::test(LookSettings::class)
            ->assertOk()
            ->assertSet('data.'.SnsSettingKey::DefaultLook->value, 'unified');
    }

    public function test_it_is_standard_before_anyone_saves(): void
    {
        Livewire::test(LookSettings::class)
            ->assertSet('data.'.SnsSettingKey::DefaultLook->value, 'standard');
    }

    public function test_saving_stores_the_value_and_clears_the_cache(): void
    {
        // The cache is what every read goes through, so a save that skipped it would leave the site
        // on the old look until the TTL expired.
        $this->assertSame(Look::Standard, app(SnsSettingService::class)->get(SnsSettingKey::DefaultLook));

        Livewire::test(LookSettings::class)
            ->set('data.'.SnsSettingKey::DefaultLook->value, 'unified')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('unified', DB::table('sns_settings')->where('key', SnsSettingKey::DefaultLook->value)->value('value'));
        $this->assertSame(Look::Unified, app(SnsSettingService::class)->get(SnsSettingKey::DefaultLook));
    }

    public function test_it_can_be_switched_back(): void
    {
        DB::table('sns_settings')->updateOrInsert(['key' => SnsSettingKey::DefaultLook->value], ['value' => 'unified']);

        Livewire::test(LookSettings::class)
            ->set('data.'.SnsSettingKey::DefaultLook->value, 'standard')
            ->call('save');

        $this->assertSame('standard', DB::table('sns_settings')->where('key', SnsSettingKey::DefaultLook->value)->value('value'));
        $this->assertSame(Look::Standard, app(SnsSettingService::class)->get(SnsSettingKey::DefaultLook));
    }

    public function test_a_guest_cannot_reach_it(): void
    {
        auth('admin')->logout();

        $this->get(LookSettings::getUrl())->assertRedirect();
    }
}
