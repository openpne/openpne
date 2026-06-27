<?php

namespace App\Filament\Resources\DiaryComments\Tables;

use App\Features\Diary\Actions\DeleteComment;
use App\Filament\Resources\Diaries\DiaryResource;
use App\Models\DiaryComment;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DiaryCommentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('diary.title')
                    ->label(__('Diary'))
                    ->searchable()
                    ->limit(30)
                    // Jump to the parent diary in the diary list (search by its title).
                    ->url(fn (DiaryComment $record): string => DiaryResource::getUrl('index', ['tableSearch' => $record->diary?->title ?? ''])),

                TextColumn::make('member.name')
                    ->label(__('Member'))
                    ->default('-') // author SET-NULL once the member withdraws
                    ->searchable(),

                TextColumn::make('body')
                    ->label(__('Body'))
                    ->searchable()
                    ->limit(60),

                TextColumn::make('created_at')
                    ->label(__('Created At'))
                    ->dateTime('Y-m-d')
                    ->sortable(),
            ])
            ->recordActions([
                // Admin delete runs DeleteComment's author-less core; the panel guard gates access.
                // Return truthy: DeleteAction reports failure when the using() result is falsy.
                DeleteAction::make()
                    ->using(function (DiaryComment $record): bool {
                        app(DeleteComment::class)->purge($record);

                        return true;
                    }),
            ])
            ->defaultSort('id', 'desc');
    }
}
