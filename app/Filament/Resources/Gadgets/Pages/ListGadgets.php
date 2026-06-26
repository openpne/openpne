<?php

namespace App\Filament\Resources\Gadgets\Pages;

use App\Filament\Resources\Gadgets\GadgetResource;
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
            // Carry the current context tab into Create so the form's Placement (and its page diagram) is
            // pre-selected to the page the operator was just looking at.
            CreateAction::make()
                ->url(fn (): string => GadgetResource::getUrl('create', ['context' => $this->activeTab])),
        ];
    }

    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        parent::reorderTable($order, $draggedRecordKey);
        app(GadgetService::class)->clearCache();
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
