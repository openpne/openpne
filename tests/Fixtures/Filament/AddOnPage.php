<?php

declare(strict_types=1);

namespace Tests\Fixtures\Filament;

use Filament\Pages\Page;
use UnitEnum;

/** A screen a composer package adds to the admin panel, outside App\Filament and its five groups. */
class AddOnPage extends Page
{
    protected static string|UnitEnum|null $navigationGroup = 'Add-on';

    protected static ?string $navigationLabel = 'Add-on page';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.add-on';
}
