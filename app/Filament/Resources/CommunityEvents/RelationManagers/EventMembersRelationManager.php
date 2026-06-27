<?php

namespace App\Filament\Resources\CommunityEvents\RelationManagers;

use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class EventMembersRelationManager extends RelationManager
{
    protected static string $relationship = 'eventMembers';

    // Admin-guard authorization; skip Filament's per-record policy checks (no member policy applies).
    protected static bool $shouldSkipAuthorization = true;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Participants');
    }

    // Writable on the ViewRecord page so an admin can remove a participant (deletes the RSVP row).
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

                TextColumn::make('created_at')
                    ->label(__('Created At'))
                    ->dateTime('Y-m-d'),
            ])
            ->recordActions([
                // Plain row delete: removes the community_event_members RSVP row (no images, no nesting).
                DeleteAction::make(),
            ])
            ->defaultSort('id', 'asc');
    }
}
