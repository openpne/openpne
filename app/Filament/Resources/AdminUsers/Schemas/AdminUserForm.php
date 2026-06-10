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

                // Password fields are stably visible (create, or editing your own account) rather
                // than appearing only once a value is typed — popping a required field into view on
                // submit is confusing. Visibility depends only on context; only requiredness reacts
                // to whether a new password is being set. Hidden entirely when editing another admin
                // (OpenPNE 3 parity: only your own password is editable).

                // Re-entering the current password guards a self password change against a
                // left-open or hijacked session (OpenPNE 3 AdminUserEditPasswordForm verifies
                // old_password). Required only when a new password is actually entered.
                TextInput::make('current_password')
                    ->label(__('Current password'))
                    ->password()
                    ->revealable()
                    ->dehydrated(false)
                    ->visible(fn (string $operation, ?Model $record): bool => $operation === 'edit' && ! self::editingAnotherAdmin($record))
                    ->required(fn (Get $get): bool => filled($get('password')))
                    ->rule('current_password:admin', fn (Get $get): bool => filled($get('password'))),

                // The model casts `password` as `hashed`, so the raw value is hashed once on save —
                // never call Hash::make() here or it double-hashes. `dehydrated(only when filled)`
                // lets an edit leave the field blank to keep the current password.
                TextInput::make('password')
                    ->label(fn (string $operation): string => $operation === 'edit' ? __('New password') : __('Password'))
                    ->password()
                    ->revealable()
                    ->live(onBlur: true)
                    ->placeholder(fn (string $operation): ?string => $operation === 'edit' ? __('Leave blank to keep current password') : null)
                    ->formatStateUsing(fn (): ?string => null)
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $operation): bool => $operation === 'create')
                    // Strength + confirmation only when a password is actually entered: always on
                    // create, on edit only when a new one is typed. Mirrors the member rule set.
                    ->rule(Password::default(), fn (Get $get): bool => filled($get('password')))
                    ->rule('confirmed', fn (Get $get): bool => filled($get('password')))
                    ->visible(fn (?Model $record): bool => ! self::editingAnotherAdmin($record)),

                TextInput::make('password_confirmation')
                    ->label(__('Confirm password'))
                    ->password()
                    ->revealable()
                    ->dehydrated(false)
                    ->required(fn (Get $get): bool => filled($get('password')))
                    ->visible(fn (?Model $record): bool => ! self::editingAnotherAdmin($record)),
            ]);
    }

    private static function editingAnotherAdmin(?Model $record): bool
    {
        return $record !== null && $record->getKey() !== auth('admin')->id();
    }
}
