<?php

namespace App\Filament\Resources\Profiles\Pages;

use App\Filament\Resources\Profiles\ProfileResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProfile extends EditRecord
{
    protected static string $resource = ProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Reload the edit page after save so the options relation manager re-mounts with the saved
     * form_type. As a separate Livewire component it would otherwise keep the pre-save form_type
     * (e.g. after switching a field to select, options stay uneditable until a manual reload).
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->record->loadMissing('translations');

        $data['caption_ja'] = $this->record->getCaption('ja_JP');
        $data['caption_en'] = $this->record->getCaption('en');
        $data['info_ja'] = $this->record->getInfo('ja_JP');
        $data['info_en'] = $this->record->getInfo('en');

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->record->setTranslation('ja_JP', $data['caption_ja'] ?? '', $data['info_ja'] ?? null);
        if (($data['caption_en'] ?? '') !== '') {
            $this->record->setTranslation('en', $data['caption_en'], $data['info_en'] ?? null);
        }
        unset($data['caption_ja'], $data['caption_en'], $data['info_ja'], $data['info_en']);

        return $data;
    }
}
