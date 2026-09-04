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

                // The model casts `password` as `hashed`; hashing here would double-hash it.
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
