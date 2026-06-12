<?php

namespace App\Filament\Resources\Navigations\Tables;

use App\Models\Navigation;
use App\Support\NavigationUri;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NavigationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label(__('Order'))
                    ->sortable(),

                TextColumn::make('caption')
                    ->label(__('Caption'))
                    ->getStateUsing(fn (Navigation $record): string => $record->getCaption(Navigation::translationLang(app()->getLocale()))),

                TextColumn::make('uri')
                    ->label(__('Link'))
                    ->wrap(),

                // Surfaces an item the renderer hides: an OpenPNE 3 value the upgrade could not
                // convert (still an @route / module/action token) is not a usable link.
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color('danger')
                    ->getStateUsing(fn (Navigation $record): ?string => NavigationUri::isRenderable($record->uri) ? null : __('Unresolved')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order');
    }
}
