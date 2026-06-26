<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\BannerSettings;
use App\Filament\Pages\DesignSettings;
use App\Filament\Pages\GadgetLayoutSettings;
use App\Filament\Resources\BannerImages\BannerImageResource;
use App\Filament\Resources\Gadgets\GadgetResource;
use App\Filament\Resources\Gadgets\Pages\CreateGadget;
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

    /** @return list<class-string> */
    private function appearanceScreens(): array
    {
        return [
            GadgetResource::class, NavigationResource::class, BannerImageResource::class,
            GadgetLayoutSettings::class, BannerSettings::class, DesignSettings::class,
        ];
    }

    public function test_the_six_appearance_screens_share_one_group(): void
    {
        foreach ($this->appearanceScreens() as $screen) {
            $this->assertSame(__('Appearance (Classic)'), $screen::getNavigationGroup(), $screen);
        }
    }

    public function test_appearance_group_builds_under_ja_in_sort_order(): void
    {
        app()->setLocale('ja');

        $groups = Filament::getCurrentPanel()->getNavigation();
        $appearance = collect($groups)->first(fn ($group) => $group->getLabel() === __('Appearance (Classic)'));

        // Matched under ja — the lazy-label registration did not silently drop the group.
        $this->assertNotNull($appearance, 'Appearance group is matched in the ja locale.');

        $labels = (new Collection($appearance->getItems()))->map->getLabel()->values()->all();
        $this->assertSame([
            __('Gadget layout'),
            __('Gadgets'),
            __('Navigation'),
            __('Banner settings'),
            __('Banner images'),
            __('Design settings'),
        ], $labels);
    }

    public function test_nav_groups_are_ordered_settings_then_appearance_then_master_data(): void
    {
        app()->setLocale('ja');

        // getNavigation() is keyed by a serialized group label; values()->all() reindexes so array_search
        // returns positions, not those keys.
        $order = (new Collection(Filament::getCurrentPanel()->getNavigation()))->map->getLabel()->values()->all();
        $settings = array_search(__('Settings'), $order, true);
        $appearance = array_search(__('Appearance (Classic)'), $order, true);
        $master = array_search(__('Master Data'), $order, true);

        $this->assertTrue(
            $settings !== false && $appearance !== false && $master !== false
                && $settings < $appearance && $appearance < $master,
            'Groups render in the registered order: Settings, Appearance, Master Data.',
        );
    }

    public function test_gadget_form_shows_field_helper_text(): void
    {
        // The Placement field keeps its helper; the Gadget field is now a radio list whose per-kind
        // descriptions are asserted in GadgetResourceTest.
        Livewire::test(CreateGadget::class)
            ->assertSee(__('Which page this gadget appears on.'));
    }
}
