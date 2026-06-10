<?php

namespace App\Filament\Resources\AdminUsers\Tables;

use App\Filament\Resources\AdminUsers\AdminUserResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
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
