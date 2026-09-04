<?php

namespace App\Filament\Resources\AdminUsers\Pages;

use App\Auth\SessionRevocation;
use App\Filament\Resources\AdminUsers\AdminUserResource;
use App\Models\AdminUser;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Password;

class EditAdminUser extends EditRecord
{
    protected static string $resource = AdminUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->changePasswordAction(),
            DeleteAction::make()
                ->hidden(fn (Model $record): bool => ! AdminUserResource::canDelete($record))
                ->before(function (DeleteAction $action, Model $record): void {
                    if (! AdminUserResource::canDelete($record)) {
                        $action->halt();
                    }
                }),
        ];
    }

    // Own account only (OpenPNE 3 editPassword parity): there is no cross-admin password change in
    // the panel.
    private function changePasswordAction(): Action
    {
        return Action::make('changePassword')
            ->label(__('Change password'))
            ->icon(Heroicon::OutlinedKey)
            ->modalSubmitActionLabel(__('Update password'))
            ->visible(fn (): bool => $this->getRecord()->getKey() === auth('admin')->id())
            ->schema([
                // Re-entering the current password guards against a left-open or hijacked session
                // (OpenPNE 3 AdminUserEditPasswordForm verifies old_password).
                TextInput::make('current_password')
                    ->label(__('Current password'))
                    ->password()
                    ->revealable()
                    ->required()
                    ->rule('current_password:admin'),

                TextInput::make('password')
                    ->label(__('New password'))
                    ->password()
                    ->revealable()
                    ->required()
                    ->rule(Password::default())
                    ->rule('confirmed'),

                TextInput::make('password_confirmation')
                    ->label(__('Confirm new password'))
                    ->password()
                    ->revealable()
                    ->required(),
            ])
            ->action(function (array $data): void {
                $record = $this->getRecord();
                // The `password` cast hashes the plaintext on save.
                $record->update(['password' => $data['password']]);

                // AuthenticateSession logs out when the session's stored password hash differs from
                // the user's, so both the in-memory user (re-stored at request end) and the session
                // value get the new hash.
                $authUser = auth('admin')->user();
                if ($authUser instanceof AdminUser && $authUser->getKey() === $record->getKey()) {
                    $authUser->forceFill(['password' => $record->getAuthPassword()]);
                    session()->put('password_hash_admin', $record->getAuthPassword());
                }

                // A changed credential must end every other authenticated foothold for this
                // administrator — other devices' sessions and all remember-me cookies — while
                // the session that just proved the current password stays signed in.
                SessionRevocation::revokeAdmin($record, session()->getId());

                Notification::make()->success()->title(__('Password updated'))->send();
            });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
