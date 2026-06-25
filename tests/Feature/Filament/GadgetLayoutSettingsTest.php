<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\GadgetLayoutSettings;
use App\Models\AdminUser;
use App\Models\Gadget;
use App\Models\Member;
use App\Services\GadgetService;
use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GadgetLayoutSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_saving_the_layout_takes_effect_and_narrows_the_rendered_zones(): void
    {
        $viewer = Member::factory()->create();
        // A `top` gadget — present under the default layoutA, gone once the home layout is layoutC.
        Gadget::create(['context' => 'home', 'zone' => 'top', 'name' => 'informationBox', 'sort_order' => 0]);

        Livewire::test(GadgetLayoutSettings::class)
            ->fillForm([
                'gadget_home_layout' => 'layoutC',
                'gadget_profile_layout' => 'layoutA',
                'gadget_login_layout' => 'layoutA',
            ])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sns_settings', ['key' => 'gadget_home_layout', 'value' => 'layoutC']);
        $this->assertSame('layoutC', app(SnsSettingService::class)->get(SnsSettingKey::GadgetHomeLayout));

        // both caches cleared, so the renderer uses layoutC (no `top` zone) immediately
        $this->assertArrayNotHasKey('top', app(GadgetService::class)->zones('home', null, $viewer));
    }

    public function test_renders_a_wireframe_radio_card_per_selectable_layout(): void
    {
        Livewire::test(GadgetLayoutSettings::class)
            ->assertSee('Layout A')
            ->assertSee('Layout B')
            ->assertSee('Layout C')
            // a radio group of cards, not a plain dropdown
            ->assertSeeHtml('role="radiogroup"')
            ->assertSeeHtml('type="radio"')
            // the zone wireframe
            ->assertSeeHtml('viewBox="0 0 240 200"')
            ->assertSeeHtml('top, sideMenu, contents, bottom');
    }

    public function test_rejects_an_unknown_layout(): void
    {
        // layoutD is sidebanner-only (not selectable); an unknown value must not reach sns_settings.
        Livewire::test(GadgetLayoutSettings::class)
            ->fillForm(['gadget_home_layout' => 'layoutD'])
            ->call('save')
            ->assertHasErrors('data.gadget_home_layout');

        $this->assertDatabaseMissing('sns_settings', ['key' => 'gadget_home_layout', 'value' => 'layoutD']);
    }
}
