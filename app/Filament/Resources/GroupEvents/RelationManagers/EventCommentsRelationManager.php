<?php

namespace App\Filament\Resources\GroupEvents\RelationManagers;

use App\Features\GroupEvent\Actions\DeleteEventComment;
use App\Models\GroupEventComment;
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
                    ->dateTime(),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->using(function (GroupEventComment $record): bool {
                        app(DeleteEventComment::class)->purge($record);

                        return true;
                    }),
            ])
            // Without an explicit heading Filament humanises the model class (GroupEventComment)
            // into an untranslated "community event comment" for the empty state.
            ->emptyStateHeading(__('No comments yet'))
            ->defaultSort('id', 'asc');
    }
}
