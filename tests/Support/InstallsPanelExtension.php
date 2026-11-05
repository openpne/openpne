<?php

declare(strict_types=1);

namespace Tests\Support;

use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Tests\Fixtures\Filament\AddOnPage;

/**
 * Boots the app with a package's Panel::configureUsing() in place, as a composer package's service
 * provider would do before the panel is built.
 */
trait InstallsPanelExtension
{
    public function createApplication(): Application
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $app->booting(function (): void {
            Panel::configureUsing(fn (Panel $panel): Panel => $panel
                ->navigationGroups([NavigationGroup::make(fn (): string => 'Add-on')])
                ->pages([AddOnPage::class]));
        });

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
