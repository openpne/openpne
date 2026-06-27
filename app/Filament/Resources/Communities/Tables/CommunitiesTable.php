<?php

namespace App\Filament\Resources\Communities\Tables;

use App\Features\Community\Actions\DeleteCommunity;
use App\Features\Community\JoinPolicy;
use App\Models\Community;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CommunitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('category'))
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->limit(40),

                TextColumn::make('category.name')
                    ->label(__('Category'))
                    ->default('-'),

                TextColumn::make('members_count')
                    ->label(__('Members'))
                    ->counts('members')
                    ->sortable(),

                TextColumn::make('register_policy')
                    ->label(__('Join policy'))
                    ->badge()
                    ->formatStateUsing(fn (JoinPolicy $state): string => __($state->label())),

                TextColumn::make('created_at')
                    ->label(__('Created At'))
                    ->dateTime('Y-m-d')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                // Admin delete runs DeleteCommunity's author-less core (purges the community's and all
                // nested topic/event/comment image bytes); return truthy or DeleteAction reports failure.
                DeleteAction::make()
                    ->using(function (Community $record): bool {
                        app(DeleteCommunity::class)->purge($record);

                        return true;
                    }),
            ])
            ->defaultSort('id', 'desc');
    }
}
