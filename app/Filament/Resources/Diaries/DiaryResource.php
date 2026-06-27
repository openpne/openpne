<?php

namespace App\Filament\Resources\Diaries;

use App\Filament\Resources\Diaries\Pages\ListDiaries;
use App\Filament\Resources\Diaries\Tables\DiariesTable;
use App\Models\Diary;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

// Admin diary monitoring (OpenPNE 3 monitoring/diary + opDiaryPlugin pc_backend). List-only:
// admins read/search and remove diaries, mirroring OpenPNE 3 which had no admin diary edit.
class DiaryResource extends Resource
{
    protected static ?string $model = Diary::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    public static function getModelLabel(): string
    {
        return __('Diary');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Diaries');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Content');
    }

    public static function table(Table $table): Table
    {
        return DiariesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDiaries::route('/'),
        ];
    }
}
