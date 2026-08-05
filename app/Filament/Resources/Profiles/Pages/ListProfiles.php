<?php

namespace App\Filament\Resources\Profiles\Pages;

use App\Filament\Resources\Pages\ListPage;
use App\Filament\Resources\Profiles\ProfileResource;
use Filament\Actions\CreateAction;

class ListProfiles extends ListPage
{
    protected static string $resource = ProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
