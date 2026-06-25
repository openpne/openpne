<?php

namespace App\Filament\Resources\Gadgets\Pages;

use App\Filament\Resources\Gadgets\GadgetResource;
use App\Filament\Resources\Gadgets\Widgets\GadgetArrangementPreview;
use App\Services\GadgetService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListGadgets extends ListRecords
{
    protected static string $resource = GadgetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            GadgetArrangementPreview::class,
        ];
    }

    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        parent::reorderTable($order, $draggedRecordKey);
        app(GadgetService::class)->clearCache();
        $this->dispatch('gadgets-arranged'); // refresh the arrangement preview
    }

    /**
     * One tab per context, so each list (and its drag-reorder) stays scoped to a single context. The
     * service derives per-zone order from this single context-wide sort, so a context tab is enough.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tabs = [];
        foreach (GadgetResource::contextOptions() as $context => $label) {
            $tabs[$context] = Tab::make($label)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('context', $context));
        }

        return $tabs;
    }
}
