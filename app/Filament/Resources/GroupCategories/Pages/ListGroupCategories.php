<?php

namespace App\Filament\Resources\GroupCategories\Pages;

use App\Filament\Resources\GroupCategories\GroupCategoryResource;
use App\Filament\Resources\Pages\ListPage;
use Filament\Actions\CreateAction;

class ListGroupCategories extends ListPage
{
    protected static string $resource = GroupCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
