<?php

namespace App\Filament\Resources\CommunityTopics\Tables;

use App\Features\CommunityTopic\Actions\DeleteTopic;
use App\Models\CommunityTopic;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CommunityTopicsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('name')
                    ->label(__('Topic Name'))
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

                TextColumn::make('comments_count')
                    ->label(__('Comments'))
                    ->counts('comments')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('Created At'))
                    ->dateTime('Y-m-d')
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                // Admin delete runs DeleteTopic's author-less core (topic + comment image bytes purged);
                // return truthy or DeleteAction reports failure after a real delete.
                DeleteAction::make()
                    ->using(function (CommunityTopic $record): bool {
                        app(DeleteTopic::class)->purge($record);

                        return true;
                    }),
            ])
            ->defaultSort('id', 'desc');
    }
}
