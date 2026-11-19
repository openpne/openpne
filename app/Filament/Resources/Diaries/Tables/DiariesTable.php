<?php

namespace App\Filament\Resources\Diaries\Tables;

use App\Features\Diary\Actions\DeleteDiary;
use App\Filament\Support\CollatedSearch;
use App\Models\Diary;
use App\Support\Database\SearchCollation;
use App\Support\Visibility;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
                    ->searchable(query: CollatedSearch::on('title'))
                    ->limit(40),

                TextColumn::make('member.name')
                    ->label(__('Member'))
                    ->default('-')
                    ->searchable(query: CollatedSearch::on('member.name'))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => SearchCollation::orderBy(
                        $query,
                        DB::table('members')->select('members.name')->whereColumn('members.id', 'diaries.member_id'),
                        $direction,
                    )),

                TextColumn::make('visibility')
                    ->label(__('Visibility'))
                    ->formatStateUsing(fn (Visibility $state): string => __($state->label())),

                TextColumn::make('created_at')
                    ->label(__('Created At'))
                    ->dateTime()
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
                // The panel guard authorizes, so purge() skips DeleteDiary's author check; the truthy
                // return is required because DeleteAction reports failure on a falsy using() result.
                DeleteAction::make()
                    ->using(function (Diary $record): bool {
                        app(DeleteDiary::class)->purge($record);

                        return true;
                    }),
            ])
            ->defaultSort('id', 'desc');
    }
}
