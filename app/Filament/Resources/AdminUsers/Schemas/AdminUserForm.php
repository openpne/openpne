<?php

namespace App\Filament\Resources\AdminUsers\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

class AdminUserForm
{
    public static function configure(Schema $schema): Schema
    {
        // Single column so the create form reads top-to-bottom (username → password → confirm)
        // rather than wrapping into a two-column grid.
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('username')
                    ->label(__('Username'))
                    ->required()
                    ->maxLength(64)
                    ->unique(
                        table: 'admin_users',
                        column: 'username',
                        ignoreRecord: true,
                    ),

                // The initial password is set only at creation. Changing an existing admin's
                // password is the dedicated "Change password" action (own account only), mirroring
                // OpenPNE 3's separate editPassword screen. The model casts `password` as `hashed`,
                // so the raw value is hashed once on save — never call Hash::make() here.
                TextInput::make('password')
                    ->label(__('Password'))
                    ->password()
                    ->revealable()
                    ->required()
                    ->rule(Password::default())
                    ->rule('confirmed')
                    ->visible(fn (string $operation): bool => $operation === 'create'),

                TextInput::make('password_confirmation')
                    ->label(__('Confirm password'))
                    ->password()
                    ->revealable()
                    ->dehydrated(false)
                    ->required()
                    ->visible(fn (string $operation): bool => $operation === 'create'),
            ]);
    }
}
