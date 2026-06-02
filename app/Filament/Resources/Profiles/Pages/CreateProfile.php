<?php

namespace App\Filament\Resources\Profiles\Pages;

use App\Filament\Resources\Profiles\ProfileResource;
use App\Services\PresetProfileService;
use Filament\Resources\Pages\CreateRecord;

class CreateProfile extends CreateRecord
{
    protected static string $resource = ProfileResource::class;

    private string $captionJa = '';

    private string $captionEn = '';

    private string $infoJa = '';

    private string $infoEn = '';

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $mode = $data['_creation_mode'] ?? 'custom';
        $presetKey = $data['_preset_key'] ?? null;
        unset($data['_creation_mode'], $data['_preset_key']);

        if ($mode === 'preset' && $presetKey !== null && $presetKey !== '') {
            $service = app(PresetProfileService::class);
            $def = $service->findByKey($presetKey);
            if ($def !== null) {
                // Lock the structure from the preset definition (OpenPNE opPresetProfileForm).
                // region_JP etc. resolve to name=op_preset_region, value_type=JP. The choices of a
                // preset select/radio come from the catalog, not profile_options, so no option
                // rows are created — usesValueColumnForChoice() drives reads off the catalog.
                $resolved = $service->nameForKey($presetKey);
                $data['name'] = $resolved['name'];
                $data['form_type'] = $def['form_type'];
                $data['value_type'] = $resolved['value_type'] ?? 'string';
                if (! empty($def['value_regexp'])) {
                    $data['value_regexp'] = $def['value_regexp'];
                }
                $data['is_unique'] = (bool) ($def['is_unique'] ?? false);
            }
        }

        $this->captionJa = $data['caption_ja'] ?? '';
        $this->captionEn = $data['caption_en'] ?? '';
        $this->infoJa = $data['info_ja'] ?? '';
        $this->infoEn = $data['info_en'] ?? '';
        unset($data['caption_ja'], $data['caption_en'], $data['info_ja'], $data['info_en']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->setTranslation('ja_JP', $this->captionJa, $this->infoJa);
        if ($this->captionEn !== '') {
            $this->record->setTranslation('en', $this->captionEn, $this->infoEn);
        }
    }
}
