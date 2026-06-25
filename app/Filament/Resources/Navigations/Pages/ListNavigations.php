<?php

namespace App\Filament\Resources\Navigations\Pages;

use App\Filament\Concerns\IndicatesClassicSurface;
use App\Filament\Resources\Navigations\NavigationResource;
use App\Services\NavigationService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListNavigations extends ListRecords
{
    use IndicatesClassicSurface;

    protected static string $resource = NavigationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        parent::reorderTable($order, $draggedRecordKey);
        app(NavigationService::class)->clearCache();
    }

    /**
     * One tab per navigation context, so each list (and its drag-reorder) stays scoped to a single
     * type — OpenPNE 3's per-type navigation editor.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tabs = [];
        foreach (NavigationResource::typeOptions() as $type => $label) {
            $tabs[$type] = Tab::make($label)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('type', $type));
        }

        return $tabs;
    }
}
