<?php

namespace App\Filament\Resources\CommunityCategories\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CommunityCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label(__('Order'))
                    ->sortable(),

                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable(),

                TextColumn::make('parent.name')
                    ->label(__('Parent'))
                    ->toggleable(),

                IconColumn::make('is_allow_member_community')
                    ->label(__('Member-creatable'))
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order');
    }
}
