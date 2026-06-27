<?php

namespace App\Filament\Resources\DiaryComments;

use App\Filament\Resources\DiaryComments\Pages\ListDiaryComments;
use App\Filament\Resources\DiaryComments\Tables\DiaryCommentsTable;
use App\Models\DiaryComment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

// Admin diary-comment monitoring (OpenPNE 3 monitoring/diary comment). List-only: read/search/delete.
class DiaryCommentResource extends Resource
{
    protected static ?string $model = DiaryComment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    public static function getModelLabel(): string
    {
        return __('Diary Comment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Diary Comments');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Content');
    }

    public static function table(Table $table): Table
    {
        return DiaryCommentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDiaryComments::route('/'),
        ];
    }
}
