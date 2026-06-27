<?php

namespace App\Filament\Resources\Diaries\Tables;

use App\Features\Diary\Actions\DeleteDiary;
use App\Models\Diary;
use App\Support\Visibility;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DiariesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('title')
                    ->label(__('Title'))
                    ->searchable()
                    ->limit(40),

                TextColumn::make('member.name')
                    ->label(__('Member'))
                    ->default('-') // author SET-NULL once the member withdraws
                    ->searchable()
                    ->sortable(),

                TextColumn::make('visibility')
                    ->label(__('Visibility'))
                    ->formatStateUsing(fn (Visibility $state): string => __($state->label())),

                TextColumn::make('created_at')
                    ->label(__('Created At'))
                    ->dateTime('Y-m-d')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('visibility')
                    ->label(__('Visibility'))
                    ->options(collect(Visibility::cases())
                        ->mapWithKeys(fn (Visibility $case): array => [$case->value => __($case->label())])
                        ->all()),
            ])
            ->recordActions([
                // Admin delete runs the author-less core: the panel guard already gates access,
                // so it bypasses DeleteDiary's Member-author check while keeping the image-byte purge.
                // Return truthy: DeleteAction reports failure when the using() result is falsy.
                DeleteAction::make()
                    ->using(function (Diary $record): bool {
                        app(DeleteDiary::class)->purge($record);

                        return true;
                    }),
            ])
            ->defaultSort('id', 'desc');
    }
}
