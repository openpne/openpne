<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\ThemeSettings;
use App\Models\AdminUser;
use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ThemeSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_it_offers_the_default_skin_and_every_bundled_theme_by_its_header_name(): void
    {
        Livewire::test(ThemeSettings::class)
            ->assertOk()
            ->assertSee(__('OpenPNE 3 default skin'))
            ->assertSee('cerulean of Bootswatch')
            ->assertSee('cerulean(light) of Bootswatch')
            ->assertSet('data.'.SnsSettingKey::ClassicTheme->value, '');
    }

    public function test_saving_stores_the_directory_name_and_clears_the_cache(): void
    {
        Livewire::test(ThemeSettings::class)
            ->set('data.'.SnsSettingKey::ClassicTheme->value, 'cerulean-light')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('cerulean-light', DB::table('sns_settings')->where('key', SnsSettingKey::ClassicTheme->value)->value('value'));
        $this->assertSame('cerulean-light', app(SnsSettingService::class)->get(SnsSettingKey::ClassicTheme));

        Livewire::test(ThemeSettings::class)
            ->set('data.'.SnsSettingKey::ClassicTheme->value, '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('', app(SnsSettingService::class)->get(SnsSettingKey::ClassicTheme));
    }

    public function test_a_theme_not_on_disk_is_refused(): void
    {
        Livewire::test(ThemeSettings::class)
            ->set('data.'.SnsSettingKey::ClassicTheme->value, 'no-such-theme')
            ->call('save')
            ->assertHasErrors('data.'.SnsSettingKey::ClassicTheme->value);
    }
}
