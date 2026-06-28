<?php

namespace App\Filament\Resources\Communities\Tables;

use App\Features\Community\Actions\AddAllMembers;
use App\Features\Community\Actions\DeleteCommunity;
use App\Features\Community\JoinPolicy;
use App\Models\Community;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
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
                    ->label(__('Member count'))
                    ->counts('members')
                    ->sortable(),

                TextColumn::make('register_policy')
                    ->label(__('Join policy'))
                    ->badge()
                    ->formatStateUsing(fn (JoinPolicy $state): string => __($state->label())),

                IconColumn::make('is_default')
                    ->label(__('Default'))
                    ->boolean()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('Created At'))
                    ->dateTime('Y-m-d')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_default')
                    ->label(__('Default')),
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('toggleDefault')
                    ->label(fn (Community $record): string => $record->is_default ? __('Unset as default') : __('Set as default'))
                    ->icon(Heroicon::OutlinedStar)
                    ->requiresConfirmation()
                    ->action(function (Community $record): void {
                        $record->update(['is_default' => ! $record->is_default]);
                        Notification::make()->success()->title(__('Saved'))->send();
                    }),

                // One-time bulk join of all existing members (auto-join of future registrations is a
                // separate, deferred frontend change). Idempotent via AddAllMembers.
                Action::make('addAllMembers')
                    ->label(__('Add all members'))
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->requiresConfirmation()
                    ->modalDescription(__('Add every member who is not already in this community. This may take a while on large sites.'))
                    ->action(function (Community $record): void {
                        $added = app(AddAllMembers::class)($record);
                        Notification::make()
                            ->success()
                            ->title(__('Members added'))
                            ->body(__(':count members added.', ['count' => $added]))
                            ->send();
                    }),
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
