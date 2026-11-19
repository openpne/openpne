<?php

namespace App\Filament\Resources\GroupTopics\Tables;

use App\Features\GroupTopic\Actions\DeleteTopic;
use App\Filament\Support\CollatedSearch;
use App\Models\GroupTopic;
use App\Support\Database\SearchCollation;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class GroupTopicsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('name')
                    ->label(__('%Topic% Name'))
                    ->searchable(query: CollatedSearch::on('name'))
                    ->limit(40),

                TextColumn::make('group.name')
                    ->label(__('%Community%'))
                    ->searchable(query: CollatedSearch::on('group.name'))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => SearchCollation::orderBy(
                        $query,
                        DB::table('groups')->select('groups.name')->whereColumn('groups.id', 'group_topics.group_id'),
                        $direction,
                    )),

                TextColumn::make('member.name')
                    ->label(__('Member'))
                    ->default('-') // author SET-NULL once the member withdraws
                    ->searchable(query: CollatedSearch::on('member.name')),

                TextColumn::make('comments_count')
                    ->label(__('Comments'))
                    ->counts('comments')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('Created At'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                // Admin delete runs DeleteTopic's author-less core (topic + comment image bytes purged);
                // return truthy or DeleteAction reports failure after a real delete.
                DeleteAction::make()
                    ->using(function (GroupTopic $record): bool {
                        app(DeleteTopic::class)->purge($record);

                        return true;
                    }),
            ])
            ->defaultSort('id', 'desc');
    }
}
