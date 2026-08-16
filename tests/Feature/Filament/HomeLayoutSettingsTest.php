<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\HomeLayoutSettings;
use App\Models\AdminUser;
use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class HomeLayoutSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_it_shows_the_stored_value(): void
    {
        DB::table('sns_settings')->updateOrInsert(['key' => SnsSettingKey::ModernUnifiedHome->value], ['value' => '1']);

        Livewire::test(HomeLayoutSettings::class)
            ->assertOk()
            ->assertSet('data.'.SnsSettingKey::ModernUnifiedHome->value, true);
    }

    public function test_it_is_off_before_anyone_saves(): void
    {
        Livewire::test(HomeLayoutSettings::class)
            ->assertSet('data.'.SnsSettingKey::ModernUnifiedHome->value, false);
    }

    public function test_saving_stores_the_value_and_clears_the_cache(): void
    {
        // The cache is what every read goes through, so a save that skipped it would leave the site
        // serving the old home until the TTL expired.
        $this->assertFalse((bool) app(SnsSettingService::class)->get(SnsSettingKey::ModernUnifiedHome));

        Livewire::test(HomeLayoutSettings::class)
            ->set('data.'.SnsSettingKey::ModernUnifiedHome->value, true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('1', DB::table('sns_settings')->where('key', SnsSettingKey::ModernUnifiedHome->value)->value('value'));
        $this->assertTrue((bool) app(SnsSettingService::class)->get(SnsSettingKey::ModernUnifiedHome));
    }

    public function test_it_can_be_turned_back_off(): void
    {
        DB::table('sns_settings')->updateOrInsert(['key' => SnsSettingKey::ModernUnifiedHome->value], ['value' => '1']);

        Livewire::test(HomeLayoutSettings::class)
            ->set('data.'.SnsSettingKey::ModernUnifiedHome->value, false)
            ->call('save');

        $this->assertSame('0', DB::table('sns_settings')->where('key', SnsSettingKey::ModernUnifiedHome->value)->value('value'));
        $this->assertFalse((bool) app(SnsSettingService::class)->get(SnsSettingKey::ModernUnifiedHome));
    }

    public function test_a_guest_cannot_reach_it(): void
    {
        auth('admin')->logout();

        $this->get(HomeLayoutSettings::getUrl())->assertRedirect();
    }
}
