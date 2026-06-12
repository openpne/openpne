<?php

namespace App\Filament\Resources\Navigations\Pages;

use App\Filament\Resources\Navigations\NavigationResource;
use App\Services\NavigationService;
use Filament\Resources\Pages\CreateRecord;

class CreateNavigation extends CreateRecord
{
    protected static string $resource = NavigationResource::class;

    private string $captionJa = '';

    private string $captionEn = '';

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->captionJa = $data['caption_ja'] ?? '';
        $this->captionEn = $data['caption_en'] ?? '';
        unset($data['caption_ja'], $data['caption_en']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->setTranslation('ja_JP', $this->captionJa);
        if ($this->captionEn !== '') {
            $this->record->setTranslation('en', $this->captionEn);
        }
        app(NavigationService::class)->clearCache();
    }
}
