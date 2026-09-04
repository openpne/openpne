<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\BannerSettings;
use App\Filament\Pages\DesignSettings;
use App\Filament\Pages\GadgetLayoutSettings;
use App\Filament\Resources\BannerImages\BannerImageResource;
use App\Filament\Resources\Gadgets\GadgetResource;
use App\Filament\Resources\Navigations\NavigationResource;
use App\Models\AdminUser;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Each Classic-only admin screen listed below must be hidden and URL-blocked under modern_only and
 * reachable under a coexistence mode.
 */
class AppearanceClassicModernOnlyTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = [DesignSettings::class, BannerSettings::class, GadgetLayoutSettings::class];

    private const RESOURCES = [GadgetResource::class, NavigationResource::class, BannerImageResource::class];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_screens_are_accessible_under_a_coexistence_mode(): void
    {
        config(['openpne.surface_mode' => 'classic_default']);

        foreach ([...self::PAGES, ...self::RESOURCES] as $screen) {
            $this->assertTrue($screen::canAccess(), "{$screen} should be accessible under classic_default");
        }
    }

    public function test_screens_are_hidden_under_modern_only(): void
    {
        config(['openpne.surface_mode' => 'modern_only']);

        foreach ([...self::PAGES, ...self::RESOURCES] as $screen) {
            $this->assertFalse($screen::canAccess(), "{$screen} should be hidden under modern_only");
        }
    }

    public function test_page_urls_are_forbidden_under_modern_only(): void
    {
        config(['openpne.surface_mode' => 'modern_only']);

        $this->get(DesignSettings::getUrl())->assertForbidden();
        $this->get(BannerSettings::getUrl())->assertForbidden();
        $this->get(GadgetLayoutSettings::getUrl())->assertForbidden();
    }

    public function test_resource_urls_are_forbidden_under_modern_only(): void
    {
        config(['openpne.surface_mode' => 'modern_only']);

        // Direct-URL access (not just the nav item) is blocked, on the list and create pages.
        foreach (self::RESOURCES as $resource) {
            $this->get($resource::getUrl('index'))->assertForbidden();
            $this->get($resource::getUrl('create'))->assertForbidden();
        }
    }
}
