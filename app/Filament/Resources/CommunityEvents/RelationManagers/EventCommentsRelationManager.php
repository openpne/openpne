<?php

namespace App\Filament\Resources\CommunityEvents\RelationManagers;

use App\Features\CommunityEvent\Actions\DeleteEventComment;
use App\Models\CommunityEventComment;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class EventCommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    // Admin-guard authorization; skip Filament's per-record policy checks (no member policy applies).
    protected static bool $shouldSkipAuthorization = true;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Comments');
    }

    // The panel makes relation managers read-only on ViewRecord pages by default; this one hosts the
    // moderation delete, so keep it writable.
    public function isReadOnly(): bool
    {
        return false;
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
                    ->using(function (CommunityEventComment $record): bool {
                        app(DeleteEventComment::class)->purge($record);

                        return true;
                    }),
            ])
            ->defaultSort('id', 'asc');
    }
}
