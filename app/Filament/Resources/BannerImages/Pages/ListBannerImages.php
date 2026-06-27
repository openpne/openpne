<?php

namespace App\Filament\Resources\BannerImages\Pages;

use App\Filament\Pages\BannerSettings;
use App\Filament\Resources\BannerImages\BannerImageResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListBannerImages extends ListRecords
{
    protected static string $resource = BannerImageResource::class;

    /** Make the two-step model explicit: images are managed here, shown where the Banner page says. */
    public function getSubheading(): ?string
    {
        return __('Add and edit banner images here. Choose where each one appears on the Banner page.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToBanner')
                ->label(__('Banner'))
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(BannerSettings::getUrl()),

            CreateAction::make(),
        ];
    }
}
