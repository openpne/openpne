<?php

namespace App\Filament\Resources\CommunityCategories\Pages;

use App\Filament\Resources\CommunityCategories\CommunityCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCommunityCategories extends ListRecords
{
    protected static string $resource = CommunityCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
