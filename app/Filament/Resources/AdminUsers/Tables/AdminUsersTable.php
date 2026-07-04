<?php

namespace App\Filament\Resources\AdminUsers\Tables;

use App\Filament\Resources\AdminUsers\AdminUserResource;
use App\Models\AdminUser;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AdminUsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('username')
                    ->label(__('Username'))
                    ->searchable()
                    ->sortable(),

                IconColumn::make('two_factor')
                    ->label(__('Two-factor authentication'))
                    ->boolean()
                    // Read the raw column so oversight of who has MFA on needs no secret decryption.
                    ->getStateUsing(fn (AdminUser $record): bool => filled($record->getRawOriginal('app_authentication_secret'))),

                TextColumn::make('created_at')
                    ->label(__('Created At'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('username')
            ->recordActions([
                EditAction::make(),
                // No bulk delete: it would bypass canDelete() and could remove the
                // primary (id 1) or the acting administrator.
                DeleteAction::make()
                    ->hidden(fn (Model $record): bool => ! AdminUserResource::canDelete($record))
                    ->before(function (DeleteAction $action, Model $record): void {
                        if (! AdminUserResource::canDelete($record)) {
                            $action->halt();
                        }
                    }),
            ]);
    }
}
