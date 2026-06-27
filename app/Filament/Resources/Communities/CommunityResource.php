<?php

namespace App\Filament\Resources\Communities;

use App\Filament\Resources\Communities\Pages\EditCommunity;
use App\Filament\Resources\Communities\Pages\ListCommunities;
use App\Filament\Resources\Communities\Schemas\CommunityForm;
use App\Filament\Resources\Communities\Tables\CommunitiesTable;
use App\Models\Community;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

// Admin community moderation (OpenPNE 3 community/list + delete). List + delete, plus an admin EDIT
// (OpenPNE 3 had none) for fixing violating communities: name / description / category / policies.
class CommunityResource extends Resource
{
    protected static ?string $model = Community::class;

    // Authorization is the `admin` guard (panel access). CommunityPolicy is member-typed and would
    // TypeError on an AdminUser, so skip Filament's per-record policy checks.
    protected static bool $shouldSkipAuthorization = true;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $recordTitleAttribute = 'name';

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
        return CommunityForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CommunitiesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCommunities::route('/'),
            'edit' => EditCommunity::route('/{record}/edit'),
        ];
    }
}
