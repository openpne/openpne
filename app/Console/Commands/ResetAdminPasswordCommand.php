<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesAdminPassword;
use App\Models\AdminUser;
use Illuminate\Console\Command;

/**
 * Lockout recovery: the panel only lets an administrator change their OWN password (OpenPNE 3
 * parity — there is no cross-admin password edit), so resetting a locked-out account is a CLI
 * action gated by server access rather than an in-panel privilege.
 */
class ResetAdminPasswordCommand extends Command
{
    use ResolvesAdminPassword;

    protected $signature = 'openpne:admin:reset-password {username : The administrator username}';

    protected $description = "Reset an administrator's password (lockout recovery)";

    public function handle(): int
    {
        $username = trim((string) $this->argument('username'));

        $admin = AdminUser::where('username', $username)->first();
        if ($admin === null) {
            $this->error("Administrator [{$username}] not found.");

            return self::FAILURE;
        }

        $password = $this->resolveValidatedPassword();
        if ($password === null) {
            return self::FAILURE;
        }

        // The `password` cast hashes the plaintext on save.
        $admin->update(['password' => $password]);

        $this->info("Password for administrator [{$username}] has been reset.");

        return self::SUCCESS;
    }
}
