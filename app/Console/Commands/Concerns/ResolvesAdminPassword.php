<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Shared password input + strength validation for the admin CLI commands.
 *
 * Prompts twice interactively (confirmation), or reads OPENPNE_ADMIN_PASSWORD for
 * non-interactive provisioning — no --password option, which would leak the secret into
 * shell history and the process list. Strength matches the member rule set (Password::default).
 */
trait ResolvesAdminPassword
{
    private function resolveValidatedPassword(): ?string
    {
        $fromEnv = getenv('OPENPNE_ADMIN_PASSWORD');
        if (is_string($fromEnv) && $fromEnv !== '') {
            $password = $confirmation = $fromEnv;
        } else {
            $password = (string) $this->secret('Password');
            $confirmation = (string) $this->secret('Confirm password');
        }

        $validator = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => ['required', 'string', Password::default(), 'confirmed']],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return null;
        }

        return $password;
    }
}
