<?php

namespace App\Filament\Resources\BannerImages\Pages;

use App\Filament\Concerns\PhoneSurfaceSubheading;
use App\Filament\Pages\BannerSettings;
use App\Filament\Resources\BannerImages\BannerImageResource;
use App\Filament\Resources\Pages\ListPage;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Support\Icons\Heroicon;

class ListBannerImages extends ListPage
{
    use PhoneSurfaceSubheading {
        getSubheading as phoneSurfaceSubheading;
    }

    protected static string $resource = BannerImageResource::class;

    /** Make the two-step model explicit: images are managed here, shown where the Banner page says. */
    public function getSubheading(): ?string
    {
        return $this->phoneSurfaceSubheading() ?? __('Add and edit banner images here. Choose where each one appears on the Banner page.');
    }

    protected function phoneSurfaceNote(): string
    {
        return __('Add and edit banner images here. Choose where each one appears on the Banner page. Banners appear only on the Classic screens and do not reach phones: every phone visitor gets the Modern screens.');
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
