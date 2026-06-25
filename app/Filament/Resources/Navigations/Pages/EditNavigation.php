<?php

namespace App\Filament\Resources\Navigations\Pages;

use App\Filament\Concerns\IndicatesClassicSurface;
use App\Filament\Resources\Navigations\NavigationResource;
use App\Services\NavigationService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditNavigation extends EditRecord
{
    use IndicatesClassicSurface;

    protected static string $resource = NavigationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->after(fn () => app(NavigationService::class)->clearCache()),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->record->loadMissing('translations');
        $data['caption_ja'] = $this->record->getCaption('ja_JP');
        $data['caption_en'] = $this->record->getCaption('en');

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->record->setTranslation('ja_JP', $data['caption_ja'] ?? '');
        if (($data['caption_en'] ?? '') !== '') {
            $this->record->setTranslation('en', $data['caption_en']);
        }
        unset($data['caption_ja'], $data['caption_en']);

        return $data;
    }

    protected function afterSave(): void
    {
        app(NavigationService::class)->clearCache();
    }
}
