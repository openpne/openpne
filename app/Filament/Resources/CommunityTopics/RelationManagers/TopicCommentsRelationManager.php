<?php

namespace App\Filament\Resources\CommunityTopics\RelationManagers;

use App\Features\CommunityTopic\Actions\DeleteTopicComment;
use App\Models\CommunityTopicComment;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TopicCommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    // Admin-guard authorization; skip Filament's per-record policy checks (no member policy applies).
    protected static bool $shouldSkipAuthorization = true;

    // The panel makes relation managers read-only on ViewRecord pages by default; this one hosts the
    // moderation delete, so keep it writable (delete only — no create/edit form is defined).
    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Comments');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('member.name')
                    ->label(__('Member'))
                    ->default('-'),

                TextColumn::make('body')
                    ->label(__('Body'))
                    ->limit(80),

                TextColumn::make('created_at')
                    ->label(__('Created At'))
                    ->dateTime('Y-m-d'),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->using(function (CommunityTopicComment $record): bool {
                        app(DeleteTopicComment::class)->purge($record);

                        return true;
                    }),
            ])
            ->defaultSort('id', 'asc');
    }
}
