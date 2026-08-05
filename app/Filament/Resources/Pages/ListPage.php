<?php

namespace App\Filament\Resources\Pages;

use Filament\Resources\Pages\ListRecords;

/**
 * A resource list page without breadcrumbs: at depth 1 the trail is just a self-link under the
 * heading that already names the list. Create/edit/view pages keep Filament's default trail.
 */
abstract class ListPage extends ListRecords
{
    /**
     * @return array<string>
     */
    public function getBreadcrumbs(): array
    {
        return [];
    }
}
