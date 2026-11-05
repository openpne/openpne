<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use Filament\Facades\Filament;
use Tests\Fixtures\Filament\AddOnPage;
use Tests\Support\InstallsPanelExtension;

/** The same conventions with a package's screen in the panel: they bind App\Filament, not the package. */
class AdminNavigationConventionsWithExtensionTest extends AdminNavigationConventionsTest
{
    use InstallsPanelExtension;

    public function test_the_package_screen_is_registered(): void
    {
        $this->assertContains(AddOnPage::class, Filament::getCurrentPanel()->getPages());
    }
}
