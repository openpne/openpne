<?php

namespace App\Filament\Resources\Members;

use App\Filament\Resources\Members\Pages\ListMembers;
use App\Filament\Resources\Members\Tables\MembersTable;
use App\Models\Member;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

// No detail or edit page: OpenPNE 3 kept member operations on the list.
class MemberResource extends Resource
{
    protected static ?string $model = Member::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return __('Member');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Members');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Members');
    }

    // Not the plural model label: that reads "Members" inside a "Members" group, and the label has
    // to say what this screen is among its siblings.
    public static function getNavigationLabel(): string
    {
        return __('Member list');
    }

    /** Neither the primary member (id 1) nor an administrator is withdrawable, nor can have login rejected. */
    public static function canDelete(Model $record): bool
    {
        return (int) $record->getKey() !== 1 && ! ($record instanceof Member && $record->isAdministrator());
    }

    public static function table(Table $table): Table
    {
        return MembersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembers::route('/'),
        ];
    }
}
