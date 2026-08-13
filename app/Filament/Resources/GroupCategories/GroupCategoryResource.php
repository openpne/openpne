<?php

namespace App\Filament\Resources\GroupCategories;

use App\Filament\Resources\GroupCategories\Pages\CreateGroupCategory;
use App\Filament\Resources\GroupCategories\Pages\EditGroupCategory;
use App\Filament\Resources\GroupCategories\Pages\ListGroupCategories;
use App\Filament\Resources\GroupCategories\Schemas\GroupCategoryForm;
use App\Filament\Resources\GroupCategories\Tables\GroupCategoriesTable;
use App\Models\GroupCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class GroupCategoryResource extends Resource
{
    protected static ?string $model = GroupCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 12;

    public static function getModelLabel(): string
    {
        return __('%Community% Category');
    }

    public static function getPluralModelLabel(): string
    {
        return __('%Community% Categories');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('%Community% category settings');
    }

    public static function form(Schema $schema): Schema
    {
        return GroupCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GroupCategoriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGroupCategories::route('/'),
            'create' => CreateGroupCategory::route('/create'),
            'edit' => EditGroupCategory::route('/{record}/edit'),
        ];
    }
}
