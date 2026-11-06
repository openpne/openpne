<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\BannerSettings;
use App\Filament\Pages\DesignSettings;
use App\Filament\Pages\ImageSettings;
use App\Filament\Pages\LoginScreenSettings;
use App\Filament\Pages\LookSettings;
use App\Filament\Pages\ThemeSettings;
use App\Models\AdminUser;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Each Modern-only admin screen listed below must be hidden and URL-blocked under classic_only, the
 * mirror of AppearanceClassicModernOnlyTest; the Classic screens stay reachable there.
 */
class ModernScreensClassicOnlyTest extends TestCase
{
    use RefreshDatabase;

    private const MODERN_PAGES = [LookSettings::class, LoginScreenSettings::class, ImageSettings::class];

    private const CLASSIC_PAGES = [DesignSettings::class, BannerSettings::class, ThemeSettings::class];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_modern_screens_are_accessible_under_a_coexistence_mode(): void
    {
        config(['openpne.surface_mode' => 'classic_default']);

        foreach (self::MODERN_PAGES as $screen) {
            $this->assertTrue($screen::canAccess(), "{$screen} should be accessible under classic_default");
        }
    }

    public function test_modern_screens_are_hidden_and_forbidden_under_classic_only(): void
    {
        config(['openpne.surface_mode' => 'classic_only']);

        foreach (self::MODERN_PAGES as $screen) {
            $this->assertFalse($screen::canAccess(), "{$screen} should be hidden under classic_only");
            $this->get($screen::getUrl())->assertForbidden();
        }
    }

    public function test_classic_screens_stay_reachable_under_classic_only(): void
    {
        config(['openpne.surface_mode' => 'classic_only']);

        foreach (self::CLASSIC_PAGES as $screen) {
            $this->assertTrue($screen::canAccess(), "{$screen} should be accessible under classic_only");
            $this->get($screen::getUrl())->assertOk();
        }
    }
}
