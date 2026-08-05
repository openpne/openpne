<?php

namespace App\Filament\Resources\CommunityCategories\Pages;

use App\Filament\Resources\CommunityCategories\CommunityCategoryResource;
use App\Filament\Resources\Pages\ListPage;
use Filament\Actions\CreateAction;

class ListCommunityCategories extends ListPage
{
    protected static string $resource = CommunityCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
