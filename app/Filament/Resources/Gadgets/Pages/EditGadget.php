<?php

namespace App\Filament\Resources\Gadgets\Pages;

use App\Filament\Resources\Gadgets\GadgetResource;
use App\Filament\Resources\Gadgets\Schemas\GadgetForm;
use App\Services\GadgetService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditGadget extends EditRecord
{
    use PersistsGadgetConfig;

    protected static string $resource = GadgetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->after(fn () => app(GadgetService::class)->clearCache()),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->record->loadMissing('configs');
        foreach ($this->record->configs as $config) {
            $data[GadgetForm::CONFIG_PREFIX.$config->name] = $config->value;
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->gadgetConfig = $this->pullConfig($data);

        return $this->stripConfig($data);
    }

    protected function afterSave(): void
    {
        $this->persistConfig();
    }
}
