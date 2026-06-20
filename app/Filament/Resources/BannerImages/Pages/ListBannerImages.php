<?php

namespace App\Filament\Resources\BannerImages\Pages;

use App\Filament\Resources\BannerImages\BannerImageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBannerImages extends ListRecords
{
    protected static string $resource = BannerImageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
