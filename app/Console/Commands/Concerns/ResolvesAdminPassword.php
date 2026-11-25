<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * There is deliberately no --password option: it would leak the secret into shell history and the
 * process list.
 */
trait ResolvesAdminPassword
{
    private function resolveValidatedPassword(string $username): ?string
    {
        $fromEnv = $this->passwordFromEnvironment();
        if ($fromEnv !== null) {
            $password = $confirmation = $fromEnv;
        } else {
            $password = (string) $this->secret('Password');
            $confirmation = (string) $this->secret('Confirm password');
        }

        // The username lets the context-word rule reject a password that embeds it.
        $validator = Validator::make(
            ['username' => $username, 'password' => $password, 'password_confirmation' => $confirmation],
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

    private function passwordFromEnvironment(): ?string
    {
        $fromEnv = getenv('OPENPNE_ADMIN_PASSWORD');

        return is_string($fromEnv) && $fromEnv !== '' ? $fromEnv : null;
    }
}
