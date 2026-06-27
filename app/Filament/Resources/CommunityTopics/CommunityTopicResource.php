<?php

namespace App\Filament\Resources\CommunityTopics;

use App\Filament\Resources\CommunityTopics\Pages\ListCommunityTopics;
use App\Filament\Resources\CommunityTopics\Pages\ViewCommunityTopic;
use App\Filament\Resources\CommunityTopics\RelationManagers\TopicCommentsRelationManager;
use App\Filament\Resources\CommunityTopics\Tables\CommunityTopicsTable;
use App\Models\CommunityTopic;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

// Admin board-topic moderation (OpenPNE 3 communityTopic/topicList). List + view (with the topic's
// comments) + delete; no admin edit (OpenPNE 3 had none). The view page hosts the comments RM.
class CommunityTopicResource extends Resource
{
    protected static ?string $model = CommunityTopic::class;

    // Authorization is the `admin` guard (panel access). The content policies (CommunityTopicPolicy
    // etc.) are member-typed and would TypeError on an AdminUser, so skip Filament's per-record checks.
    protected static bool $shouldSkipAuthorization = true;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    // Shows the topic name as the view-page title (no infolist; the page hosts the comments RM).
    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Topic');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Topics');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Content');
    }

    public static function table(Table $table): Table
    {
        return CommunityTopicsTable::configure($table);
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
            'index' => ListCommunityTopics::route('/'),
            'view' => ViewCommunityTopic::route('/{record}'),
        ];
    }
}
