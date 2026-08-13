<?php

namespace App\Filament\Resources\Groups;

use App\Filament\Resources\Groups\Pages\EditGroup;
use App\Filament\Resources\Groups\Pages\ListGroups;
use App\Filament\Resources\Groups\Schemas\GroupForm;
use App\Filament\Resources\Groups\Tables\GroupsTable;
use App\Models\Group;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

// Admin community moderation. List + delete, plus an admin EDIT (OpenPNE 3 had none) for
// fixing violating groups: name / description / category / policies.
class GroupResource extends Resource
{
    protected static ?string $model = Group::class;

    // Authorization is the `admin` guard (panel access). GroupPolicy is member-typed and would
    // TypeError on an AdminUser, so skip Filament's per-record policy checks.
    protected static bool $shouldSkipAuthorization = true;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return __('%Community%');
    }

    public static function getPluralModelLabel(): string
    {
        return __('%Communities%');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Content');
    }

    public static function form(Schema $schema): Schema
    {
        return GroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GroupsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGroups::route('/'),
            'edit' => EditGroup::route('/{record}/edit'),
        ];
    }
}
