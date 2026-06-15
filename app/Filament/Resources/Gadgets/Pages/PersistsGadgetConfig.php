<?php

namespace App\Filament\Resources\Gadgets\Pages;

use App\Filament\Resources\Gadgets\Schemas\GadgetForm;
use App\Services\GadgetService;
use Illuminate\Support\Facades\DB;

/**
 * Moves the `config_*` form inputs to/from gadget_configs (the model itself has no config columns),
 * shared by the Create and Edit pages, and clears the gadget cache after a write.
 */
trait PersistsGadgetConfig
{
    /** @var array<string, mixed> */
    private array $gadgetConfig = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> the config inputs, by config name (prefix stripped)
     */
    private function pullConfig(array $data): array
    {
        $config = [];
        foreach ($data as $key => $value) {
            if (str_starts_with($key, GadgetForm::CONFIG_PREFIX)) {
                $config[substr($key, strlen(GadgetForm::CONFIG_PREFIX))] = $value;
            }
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> $data without its config inputs (so the model save sees only columns)
     */
    private function stripConfig(array $data): array
    {
        return array_filter(
            $data,
            fn (string $key): bool => ! str_starts_with($key, GadgetForm::CONFIG_PREFIX),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function persistConfig(): void
    {
        foreach ($this->gadgetConfig as $name => $value) {
            DB::table('gadget_configs')->updateOrInsert(
                ['gadget_id' => $this->record->getKey(), 'name' => $name],
                ['value' => $value === null ? null : (string) $value],
            );
        }

        app(GadgetService::class)->clearCache();
    }
}
