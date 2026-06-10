<?php

namespace App\Filament\Resources\AdminUsers\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Password;

class AdminUserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('username')
                    ->label(__('Username'))
                    ->required()
                    ->maxLength(64)
                    ->unique(
                        table: 'admin_user',
                        column: 'username',
                        ignoreRecord: true,
                    ),

                // The model casts `password` as `hashed`, so the raw value is hashed once on
                // save — never call Hash::make() here or it double-hashes. `dehydrated(only
                // when filled)` lets an edit leave the field blank to keep the current password;
                // `formatStateUsing(null)` keeps the stored hash out of the field on edit.
                // OpenPNE 3 parity: only your own password is editable, so the field is hidden
                // when editing another administrator.
                TextInput::make('password')
                    ->label(__('Password'))
                    ->password()
                    ->revealable()
                    ->live(onBlur: true)
                    ->placeholder(fn (string $operation): ?string => $operation === 'edit' ? __('Leave blank to keep current password') : null)
                    ->formatStateUsing(fn (): ?string => null)
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $operation): bool => $operation === 'create')
                    // Strength + confirmation only when a password is actually entered: always on
                    // create, on edit only when a new one is typed (so a blank edit skips both and
                    // keeps the current password). Mirrors the member rule set (Password::default).
                    ->rule(Password::default(), fn (Get $get): bool => filled($get('password')))
                    ->rule('confirmed', fn (Get $get): bool => filled($get('password')))
                    ->hidden(fn (?Model $record): bool => self::editingAnotherAdmin($record)),

                // Changing your own password requires re-entering the current one (OpenPNE 3
                // AdminUserEditPasswordForm verifies old_password). Without this, a left-open or
                // hijacked admin session could change the password. Only on edit + when a new
                // password is being set; on create there is no current password.
                TextInput::make('current_password')
                    ->label(__('Current password'))
                    ->password()
                    ->revealable()
                    ->dehydrated(false)
                    ->visible(fn (string $operation, Get $get): bool => $operation === 'edit' && filled($get('password')))
                    ->required(fn (string $operation, Get $get): bool => $operation === 'edit' && filled($get('password')))
                    ->rule('current_password:admin', fn (string $operation, Get $get): bool => $operation === 'edit' && filled($get('password'))),

                TextInput::make('password_confirmation')
                    ->label(__('Confirm password'))
                    ->password()
                    ->revealable()
                    ->dehydrated(false)
                    ->required(fn (Get $get): bool => filled($get('password')))
                    ->visible(fn (string $operation, Get $get, ?Model $record): bool => ! self::editingAnotherAdmin($record)
                        && ($operation === 'create' || filled($get('password')))),
            ]);
    }

    private static function editingAnotherAdmin(?Model $record): bool
    {
        return $record !== null && $record->getKey() !== auth('admin')->id();
    }
}
