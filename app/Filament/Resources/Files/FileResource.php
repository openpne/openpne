<?php

namespace App\Filament\Resources\Files;

use App\Filament\Resources\Files\Pages\ListFiles;
use App\Filament\Resources\Files\Tables\FilesTable;
use App\Models\File;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

// Admin file monitor (OpenPNE 3 monitoring/fileList + monitoring/imageList). Lists every uploaded
// file across the morph-owned content; admins preview, download, and delete. Read-only (no create —
// admin upload is a separate feature). Bytes are served by the admin-gated AdminFileController.
class FileResource extends Resource
{
    protected static ?string $model = File::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

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
