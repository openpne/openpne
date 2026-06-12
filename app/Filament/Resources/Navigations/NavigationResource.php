<?php

namespace App\Filament\Resources\Navigations;

use App\Filament\Resources\Navigations\Pages\CreateNavigation;
use App\Filament\Resources\Navigations\Pages\EditNavigation;
use App\Filament\Resources\Navigations\Pages\ListNavigations;
use App\Filament\Resources\Navigations\Schemas\NavigationForm;
use App\Filament\Resources\Navigations\Tables\NavigationsTable;
use App\Models\Navigation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NavigationResource extends Resource
{
    protected static ?string $model = Navigation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBars3;

    protected static ?int $navigationSort = 3;

    public static function getModelLabel(): string
    {
        return __('Navigation item');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Navigation');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Master Data');
    }

    /**
     * Selectable navigation contexts, in display order. Keys are the stored `type`; the global
     * pair drives `#globalNav`, the rest `#localNav`.
     *
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            'secure_global' => __('Global navigation (members)'),
            'insecure_global' => __('Global navigation (guests)'),
            'default' => __('Local navigation (own pages)'),
            'friend' => __('Local navigation (member pages)'),
            'community' => __('Local navigation (community pages)'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('translations');
    }

    public static function form(Schema $schema): Schema
    {
        return NavigationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NavigationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNavigations::route('/'),
            'create' => CreateNavigation::route('/create'),
            'edit' => EditNavigation::route('/{record}/edit'),
        ];
    }
}
