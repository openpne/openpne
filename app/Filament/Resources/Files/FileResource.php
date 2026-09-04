<?php

namespace App\Filament\Resources\Files;

use App\Filament\Resources\Files\Pages\ListFiles;
use App\Filament\Resources\Files\Tables\FilesTable;
use App\Models\File;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

// No create page: the only admin upload is the public-image header action on the list.
class FileResource extends Resource
{
    protected static ?string $model = File::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static ?int $navigationSort = 6;

    public static function getModelLabel(): string
    {
        return __('File');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Files');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Content');
    }

    public static function table(Table $table): Table
    {
        return FilesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFiles::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
