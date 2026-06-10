<?php

namespace App\Filament\Resources\AdminUsers\Pages;

use App\Filament\Resources\AdminUsers\AdminUserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditAdminUser extends EditRecord
{
    protected static string $resource = AdminUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->hidden(fn (Model $record): bool => ! AdminUserResource::canDelete($record))
                ->before(function (DeleteAction $action, Model $record): void {
                    if (! AdminUserResource::canDelete($record)) {
                        $action->halt();
                    }
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    // Keep the current session authenticated after changing your own password. AuthenticateSession
    // compares the session's stored password hash against the user's current hash and logs out on
    // mismatch; without resyncing, the operator would be bounced to login on the next request.
    protected function afterSave(): void
    {
        $authUser = auth('admin')->user();

        if ($authUser === null || ! $authUser->is($this->getRecord())) {
            return;
        }

        $newHash = $this->getRecord()->getAuthPassword();

        if ($authUser->getAuthPassword() === $newHash) {
            return; // password unchanged
        }

        $authUser->forceFill(['password' => $newHash]);
        session()->put('password_hash_admin', $newHash);
    }
}
