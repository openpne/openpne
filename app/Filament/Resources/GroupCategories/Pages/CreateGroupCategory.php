<?php

namespace App\Filament\Resources\GroupCategories\Pages;

use App\Filament\Resources\GroupCategories\GroupCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateGroupCategory extends CreateRecord
{
    protected static string $resource = GroupCategoryResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
