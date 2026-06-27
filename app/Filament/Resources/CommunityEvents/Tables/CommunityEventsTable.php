<?php

namespace App\Filament\Resources\CommunityEvents\Tables;

use App\Features\CommunityEvent\Actions\DeleteEvent;
use App\Models\CommunityEvent;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CommunityEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('name')
                    ->label(__('Event Name'))
                    ->searchable()
                    ->limit(40),

                TextColumn::make('community.name')
                    ->label(__('%Community%'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('member.name')
                    ->label(__('Member'))
                    ->default('-') // author SET-NULL once the member withdraws
                    ->searchable(),

                TextColumn::make('open_date')
                    ->label(__('Event Date'))
                    ->dateTime('Y-m-d')
                    ->sortable(),

                TextColumn::make('event_members_count')
                    ->label(__('Participants'))
                    ->counts('eventMembers')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('Created At'))
                    ->dateTime('Y-m-d')
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                // Admin delete runs DeleteEvent's author-less core (event + comment image bytes purged);
                // return truthy or DeleteAction reports failure after a real delete.
                DeleteAction::make()
                    ->using(function (CommunityEvent $record): bool {
                        app(DeleteEvent::class)->purge($record);

                        return true;
                    }),
            ])
            ->defaultSort('id', 'desc');
    }
}
