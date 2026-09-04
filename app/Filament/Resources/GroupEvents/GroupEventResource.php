<?php

namespace App\Filament\Resources\GroupEvents;

use App\Filament\Resources\GroupEvents\Pages\ListGroupEvents;
use App\Filament\Resources\GroupEvents\Pages\ViewGroupEvent;
use App\Filament\Resources\GroupEvents\RelationManagers\EventCommentsRelationManager;
use App\Filament\Resources\GroupEvents\RelationManagers\EventMembersRelationManager;
use App\Filament\Resources\GroupEvents\Tables\GroupEventsTable;
use App\Models\GroupEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class GroupEventResource extends Resource
{
    protected static ?string $model = GroupEvent::class;

    // The panel guard authorizes; GroupEventPolicy is member-typed and would TypeError on an
    // AdminUser, so Filament's per-record checks are skipped.
    protected static bool $shouldSkipAuthorization = true;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    // Shows the event name as the view-page title (no infolist; the page hosts the relation managers).
    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 3;

    public static function getModelLabel(): string
    {
        return __('Event');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Events');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Content');
    }

    public static function table(Table $table): Table
    {
        return GroupEventsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            EventCommentsRelationManager::class,
            EventMembersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGroupEvents::route('/'),
            'view' => ViewGroupEvent::route('/{record}'),
        ];
    }
}
