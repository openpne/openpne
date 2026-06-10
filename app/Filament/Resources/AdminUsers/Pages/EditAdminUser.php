<?php

namespace App\Filament\Resources\AdminUsers\Pages;

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

    // Password change is a dedicated action (OpenPNE 3 editPassword parity), available only for
    // your own account — no cross-admin password change in the panel. The modal lays the fields
    // out one per row (current → new → confirm), all required, so the required markers are honest
    // rather than appearing only once you start typing.
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

                // Keep the current session authenticated: AuthenticateSession compares the session's
                // stored password hash against the user's current hash and logs out on mismatch.
                // Sync the in-memory authenticated user (so the end-of-request re-store uses the new
                // hash) and the session value directly.
                $authUser = auth('admin')->user();
                if ($authUser instanceof AdminUser && $authUser->getKey() === $record->getKey()) {
                    $authUser->forceFill(['password' => $record->getAuthPassword()]);
                    session()->put('password_hash_admin', $record->getAuthPassword());
                }

                Notification::make()->success()->title(__('Password updated'))->send();
            });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
