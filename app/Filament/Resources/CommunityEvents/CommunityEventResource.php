<?php

namespace App\Filament\Resources\CommunityEvents;

use App\Filament\Resources\CommunityEvents\Pages\ListCommunityEvents;
use App\Filament\Resources\CommunityEvents\Pages\ViewCommunityEvent;
use App\Filament\Resources\CommunityEvents\RelationManagers\EventCommentsRelationManager;
use App\Filament\Resources\CommunityEvents\RelationManagers\EventMembersRelationManager;
use App\Filament\Resources\CommunityEvents\Tables\CommunityEventsTable;
use App\Models\CommunityEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

// Admin event moderation (OpenPNE 3 communityTopic/eventList). List + view (with the event's comments
// and RSVP roster) + delete; no admin edit. The view page hosts the two relation managers.
class CommunityEventResource extends Resource
{
    protected static ?string $model = CommunityEvent::class;

    // Authorization is the `admin` guard (panel access). The content policies (CommunityEventPolicy)
    // are member-typed and would TypeError on an AdminUser, so skip Filament's per-record checks.
    protected static bool $shouldSkipAuthorization = true;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    // Shows the event name as the view-page title (no infolist; the page hosts the relation managers).
    protected static ?string $recordTitleAttribute = 'name';

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
        return CommunityEventsTable::configure($table);
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
            'index' => ListCommunityEvents::route('/'),
            'view' => ViewCommunityEvent::route('/{record}'),
        ];
    }
}
