<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\BannerSettings;
use App\Filament\Pages\DesignSettings;
use App\Filament\Pages\GadgetLayoutSettings;
use App\Filament\Pages\SurfaceGuide;
use App\Filament\Resources\BannerImages\BannerImageResource;
use App\Filament\Resources\Gadgets\GadgetResource;
use App\Filament\Resources\Gadgets\Pages\CreateGadget;
use App\Filament\Resources\Gadgets\Pages\ListGadgets;
use App\Filament\Resources\Navigations\NavigationResource;
use App\Models\AdminUser;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\TestCase;

class AppearanceNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
        app()->setLocale('en');
    }

    public function test_the_six_appearance_screens_share_one_group(): void
    {
        foreach ([
            GadgetResource::class, NavigationResource::class, BannerImageResource::class,
            GadgetLayoutSettings::class, BannerSettings::class, DesignSettings::class, SurfaceGuide::class,
        ] as $screen) {
            $this->assertSame(__('Appearance'), $screen::getNavigationGroup(), $screen);
        }
    }

    public function test_appearance_group_builds_under_ja_with_the_guide_first(): void
    {
        app()->setLocale('ja');

        $groups = Filament::getCurrentPanel()->getNavigation();
        $appearance = collect($groups)->first(fn ($group) => $group->getLabel() === __('Appearance'));

        // Matched under ja — the lazy-label registration did not silently drop the group.
        $this->assertNotNull($appearance, 'Appearance group is matched in the ja locale.');

        $labels = (new Collection($appearance->getItems()))->map->getLabel()->values();
        $this->assertSame(__('Display mode'), $labels->first()); // sort 0
        foreach ([__('Gadgets'), __('Navigation'), __('Banner images')] as $expected) {
            $this->assertContains($expected, $labels->all());
        }
    }

    public function test_nav_groups_are_ordered_settings_then_appearance_then_master_data(): void
    {
        app()->setLocale('ja');

        // getNavigation() is keyed by a serialized group label; values()->all() reindexes so array_search
        // returns positions, not those keys.
        $order = (new Collection(Filament::getCurrentPanel()->getNavigation()))->map->getLabel()->values()->all();
        $settings = array_search(__('Settings'), $order, true);
        $appearance = array_search(__('Appearance'), $order, true);
        $master = array_search(__('Master Data'), $order, true);

        $this->assertTrue(
            $settings !== false && $appearance !== false && $master !== false
                && $settings < $appearance && $appearance < $master,
            'Groups render in the registered order: Settings, Appearance, Master Data.',
        );
    }

    public function test_classic_scope_note_shows_on_list_and_create(): void
    {
        $note = __('These settings affect the Classic view only.');

        Livewire::test(ListGadgets::class)->assertSee($note);
        Livewire::test(CreateGadget::class)->assertSee($note);
    }

    public function test_surface_guide_reflects_the_configured_default_surface(): void
    {
        config(['openpne.tenant_mode' => 'mixed', 'openpne.tenant_default_surface' => 'classic']);
        Livewire::test(SurfaceGuide::class)
            ->assertSee(__('By default members see the Classic view.'))
            ->assertSee(__('The appearance settings in this section affect the Classic view. Modern has its own design settings.'));

        config(['openpne.tenant_mode' => 'modern_only']);
        Livewire::test(SurfaceGuide::class)
            ->assertSee(__('Members see the Modern view on canonical URLs (modern_only mode).'));
    }

    public function test_gadget_form_shows_field_helper_text(): void
    {
        Livewire::test(CreateGadget::class)
            ->assertSee(__('Which page this gadget appears on.'))
            ->assertSee(__('The kind of content to show.'));
    }
}
