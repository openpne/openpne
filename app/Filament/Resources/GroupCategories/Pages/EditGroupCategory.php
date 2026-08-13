<?php

namespace App\Filament\Resources\GroupCategories\Pages;

use App\Filament\Resources\GroupCategories\GroupCategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditGroupCategory extends EditRecord
{
    protected static string $resource = GroupCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
