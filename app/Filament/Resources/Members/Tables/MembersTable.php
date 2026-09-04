<?php

namespace App\Filament\Resources\Members\Tables;

use App\Features\Member\Actions\AllowMemberLogin;
use App\Features\Member\Actions\MfaResetUnavailable;
use App\Features\Member\Actions\RejectMemberLogin;
use App\Features\Member\Actions\RequestMfaReset;
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

                IconColumn::make('two_factor')
                    ->label(__('Two-factor authentication'))
                    ->boolean()
                    // The support-conversation starting point and the reason the reset action shows or
                    // hides — read from the raw columns so oversight needs no secret decryption.
                    ->getStateUsing(fn (Member $record): bool => self::hasLiveFactor($record)),

                TextColumn::make('created_at')
                    ->label(__('Created At'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_login_rejected')
                    ->label(__('Login rejected')),
            ])
            ->recordActions([
                self::banAction(),
                self::unbanAction(),
                self::sendMfaResetAction(),
                // The panel guard authorizes, so the author-less WithdrawMember runs directly; the truthy
                // return is required because DeleteAction reports failure on a falsy using() result.
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

    /** Freeze a member's login (is_login_rejected). Never offered for the primary member. */
    private static function banAction(): Action
    {
        return Action::make('ban')
            ->label(__('Reject login'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (Member $record): bool => ! $record->is_login_rejected && MemberResource::canDelete($record))
            // Defense-in-depth: visible() only hides the action; a forged mount must not be able to
            // freeze the primary member's login.
            ->before(function (Action $action, Member $record): void {
                if (! MemberResource::canDelete($record)) {
                    $action->halt();
                }
            })
            ->action(function (Member $record): void {
                app(RejectMemberLogin::class)($record);
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
                app(AllowMemberLogin::class)($record);
                Notification::make()
                    ->title(__('The member can log in again'))
                    ->success()
                    ->send();
            });
    }

    /**
     * The link reaches only the member's registered mailbox and needs the member's own password, so
     * it cannot take over an account. Hence no primary-member gate and no is_login_rejected gate: a
     * ban is enforced at login, and recovery is orthogonal to moderation.
     */
    private static function sendMfaResetAction(): Action
    {
        return Action::make('sendMfaReset')
            ->label(__('Send 2FA reset link'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('Send 2FA reset link'))
            ->modalDescription(fn (Member $record): string => __("A reset link will be sent to :email (the member's registered address, which cannot be changed here). The member opens it and enters their account password to turn two-factor authentication off — the link alone cannot. It expires after a while, and sending again invalidates any earlier link. Confirm the member's identity before sending.", ['email' => (string) $record->email]))
            ->modalSubmitActionLabel(__('Send 2FA reset link'))
            ->visible(fn (Member $record): bool => self::hasLiveFactor($record) && filled($record->email))
            // Defense-in-depth: visible() only hides the action; a forged mount, or a factor disabled
            // between render and click, must not mint a link the member cannot use (RequestMfaReset also
            // re-checks under a lock — this is the graceful UX halt).
            ->before(function (Action $action, Member $record): void {
                if (! self::hasLiveFactor($record) || blank($record->email)) {
                    Notification::make()
                        ->title(__('Two-factor authentication is no longer active for this member'))
                        ->warning()
                        ->send();
                    $action->halt();
                }
            })
            ->action(function (Member $record): void {
                try {
                    app(RequestMfaReset::class)($record);
                } catch (MfaResetUnavailable) {
                    // The factor or address was invalidated between before() and here; RequestMfaReset's
                    // locked recheck is the backstop, so degrade to the warning instead of a 500.
                    Notification::make()
                        ->title(__('Two-factor authentication is no longer active for this member'))
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__("A 2FA reset link has been sent to the member's registered address"))
                    ->success()
                    ->send();
            });
    }

    /**
     * Whether the member has a LIVE (confirmed) two-factor factor. Read from the raw columns so neither
     * this nor the reset action ever needs to decrypt the secret.
     */
    private static function hasLiveFactor(Member $record): bool
    {
        return filled($record->getRawOriginal('two_factor_secret'))
            && filled($record->getRawOriginal('two_factor_confirmed_at'));
    }
}
