<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesAdminPassword;
use App\Models\AdminUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Bootstraps the first administrator on a fresh single-site install (and adds further
 * accounts). The panel's in-app CRUD assumes an administrator can already log in, so this
 * is the supported path to the first account; it is scriptable from the fleet provisioner.
 */
class CreateAdminUserCommand extends Command
{
    use ResolvesAdminPassword;

    protected $signature = 'openpne:admin:create {username? : The administrator username}';

    protected $description = 'Create an administrator account for the admin panel';

    public function handle(): int
    {
        $username = trim((string) ($this->argument('username') ?? $this->ask('Username')));

        $validator = Validator::make(['username' => $username], [
            'username' => ['required', 'string', 'max:64', Rule::unique('admin_user', 'username')],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $password = $this->resolveValidatedPassword();
        if ($password === null) {
            return self::FAILURE;
        }

        // The `password` cast hashes the plaintext on save.
        AdminUser::create(['username' => $username, 'password' => $password]);

        $this->info("Administrator [{$username}] created.");

        return self::SUCCESS;
    }
}
