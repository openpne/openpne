<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use SensitiveParameter;

/**
 * Administrators log in by username, not email (OpenPNE 3 has no administrator
 * email). The form keeps Filament's `email` field key but presents it as a
 * username input, and the credentials passed to the `admin` guard are keyed by
 * `username` so the provider looks up the `admin_users.username` column.
 *
 * "Remember me" is dropped: a recaller cookie authenticates through the guard
 * middleware, which never presents the TOTP challenge, so a long-lived remember
 * cookie would silently bypass admin two-factor auth. A privileged panel signs
 * in per session instead.
 */
class Login extends BaseLogin
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
        ]);
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('Username'))
            ->required()
            ->autocomplete()
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        return [
            'username' => $data['email'],
            'password' => $data['password'],
        ];
    }
}
