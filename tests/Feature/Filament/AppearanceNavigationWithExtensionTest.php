<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Tests\Support\InstallsPanelExtension;

/** The same sidebar assertions with a package's group in the panel. */
class AppearanceNavigationWithExtensionTest extends AppearanceNavigationTest
{
    use InstallsPanelExtension;

    public function test_the_package_group_renders_with_its_screen(): void
    {
        app()->setLocale('ja');

        $group = (new Collection(Filament::getCurrentPanel()->getNavigation()))
            ->first(fn ($group) => $group->getLabel() === 'Add-on');

        $this->assertNotNull($group, 'The package group is in the sidebar.');
        $this->assertSame(['Add-on page'], (new Collection($group->getItems()))->map->getLabel()->values()->all());
    }
}
