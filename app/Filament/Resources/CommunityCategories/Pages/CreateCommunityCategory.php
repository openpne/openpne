<?php

namespace App\Filament\Resources\CommunityCategories\Pages;

use App\Filament\Resources\CommunityCategories\CommunityCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCommunityCategory extends CreateRecord
{
    protected static string $resource = CommunityCategoryResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
