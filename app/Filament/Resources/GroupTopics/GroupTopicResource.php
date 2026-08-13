<?php

namespace App\Filament\Resources\GroupTopics;

use App\Filament\Resources\GroupTopics\Pages\ListGroupTopics;
use App\Filament\Resources\GroupTopics\Pages\ViewGroupTopic;
use App\Filament\Resources\GroupTopics\RelationManagers\TopicCommentsRelationManager;
use App\Filament\Resources\GroupTopics\Tables\GroupTopicsTable;
use App\Models\GroupTopic;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

// Admin board-topic moderation. List + view (with the topic's comments) + delete; no admin
// edit (OpenPNE 3 had none). The view page hosts the comments RM.
class GroupTopicResource extends Resource
{
    protected static ?string $model = GroupTopic::class;

    // Authorization is the `admin` guard (panel access). The content policies (GroupTopicPolicy
    // etc.) are member-typed and would TypeError on an AdminUser, so skip Filament's per-record checks.
    protected static bool $shouldSkipAuthorization = true;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    // Shows the topic name as the view-page title (no infolist; the page hosts the comments RM).
    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return __('%Topic%');
    }

    public static function getPluralModelLabel(): string
    {
        return __('%Topics%');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Content');
    }

    public static function table(Table $table): Table
    {
        return GroupTopicsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            TopicCommentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGroupTopics::route('/'),
            'view' => ViewGroupTopic::route('/{record}'),
        ];
    }
}
