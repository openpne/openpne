<?php

namespace App\Filament\Resources\Members\Tables;

use App\Features\Member\Actions\WithdrawMember;
use App\Filament\Resources\Members\MemberResource;
use App\Models\Member;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class MembersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label(__('Email'))
                    ->default('-')
                    ->searchable(),

                IconColumn::make('is_login_rejected')
                    ->label(__('Login rejected'))
                    ->boolean()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('Created At'))
                    ->dateTime('Y-m-d')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_login_rejected')
                    ->label(__('Login rejected')),
            ])
            ->recordActions([
                self::banAction(),
                self::unbanAction(),
                // Withdrawal is permanent member deletion; the panel guard authorizes it, so the
                // author-less WithdrawMember service runs directly. Hidden for the primary member.
                // Return truthy: DeleteAction reports failure when the using() result is falsy.
                DeleteAction::make()
                    ->label(__('Withdraw'))
                    ->hidden(fn (Member $record): bool => ! MemberResource::canDelete($record))
                    ->using(function (Member $record): bool {
                        app(WithdrawMember::class)($record);

                        return true;
                    }),
            ])
            ->defaultSort('id', 'desc');
    }

    /** Freeze a member's login (OpenPNE 3 is_login_rejected). Never offered for the primary member. */
    private static function banAction(): Action
    {
        return Action::make('ban')
            ->label(__('Reject login'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (Member $record): bool => ! $record->is_login_rejected && MemberResource::canDelete($record))
            // Defense-in-depth: visible() only hides the action; a forged mount must not be able to
            // freeze the primary member's login. Mirrors AdminUser delete's before()/halt() guard.
            ->before(function (Action $action, Member $record): void {
                if (! MemberResource::canDelete($record)) {
                    $action->halt();
                }
            })
            ->action(function (Member $record): void {
                // Direct assignment: is_login_rejected is outside the model's mass-assignable set.
                $record->is_login_rejected = true;
                $record->save();
                Notification::make()
                    ->title(__('The member can no longer log in'))
                    ->success()
                    ->send();
            });
    }

    private static function unbanAction(): Action
    {
        return Action::make('unban')
            ->label(__('Allow login'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (Member $record): bool => (bool) $record->is_login_rejected)
            ->action(function (Member $record): void {
                $record->is_login_rejected = false;
                $record->save();
                Notification::make()
                    ->title(__('The member can log in again'))
                    ->success()
                    ->send();
            });
    }
}
