<?php

namespace App\Filament\Resources\Gadgets\Pages;

use App\Filament\Concerns\IndicatesClassicSurface;
use App\Filament\Resources\Gadgets\GadgetResource;
use Filament\Resources\Pages\CreateRecord;

class CreateGadget extends CreateRecord
{
    use IndicatesClassicSurface;
    use PersistsGadgetConfig;

    protected static string $resource = GadgetResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->gadgetConfig = $this->pullConfig($data);

        return $this->stripConfig($data);
    }

    protected function afterCreate(): void
    {
        $this->persistConfig();
    }
}
