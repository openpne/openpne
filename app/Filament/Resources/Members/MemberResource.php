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

// Admin member moderation (OpenPNE 3 pc_backend member module). List-only: admins read/search,
// freeze logins (is_login_rejected), and withdraw members. No detail/edit page, mirroring OpenPNE 3
// which kept member operations on the list. Nav group is the tentative `Content` moderation bucket.
class MemberResource extends Resource
{
    protected static ?string $model = Member::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function getModelLabel(): string
    {
        return __('Member');
    }

    // `Members` already means "member count" elsewhere; reuse the singular so the list title and nav
    // label render "メンバー" rather than "メンバー数".
    public static function getPluralModelLabel(): string
    {
        return __('Member');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Content');
    }

    /** The primary member (id 1) is the initial administrator account and is never withdrawable. */
    public static function canDelete(Model $record): bool
    {
        return (int) $record->getKey() !== 1;
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
