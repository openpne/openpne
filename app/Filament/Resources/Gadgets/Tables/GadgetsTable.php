<?php

namespace App\Filament\Resources\Gadgets\Tables;

use App\Filament\Resources\Gadgets\GadgetResource;
use App\Gadgets\GadgetKindRegistry;
use App\Models\Gadget;
use App\Services\GadgetService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GadgetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label(__('Sort Order'))
                    ->sortable(),

                TextColumn::make('zone')
                    ->label(__('Zone'))
                    ->getStateUsing(fn (Gadget $record): string => GadgetResource::zoneOptions($record->context)[$record->zone] ?? $record->zone),

                TextColumn::make('name')
                    ->label(__('Gadget'))
                    ->getStateUsing(fn (Gadget $record): string => GadgetKindRegistry::find($record->name)?->label() ?? $record->name)
                    ->description(fn (Gadget $record): ?string => GadgetKindRegistry::find($record->name)?->description() ?: null),

                // Surfaces a row the renderer hides: a `name` with no registered kind (an OpenPNE 3
                // gadget not yet ported) cannot render.
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color('danger')
                    ->getStateUsing(fn (Gadget $record): ?string => GadgetKindRegistry::find($record->name) === null ? __('Unsupported') : null),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->after(fn () => app(GadgetService::class)->clearCache()),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order');
    }
}
