<?php

namespace App\Console\Commands;

use App\Auth\SessionRevocation;
use App\Console\Commands\Concerns\ResolvesAdminPassword;
use App\Models\AdminUser;
use App\Support\SecurityLog;
use Illuminate\Console\Command;

/**
 * The panel only lets an administrator change their own password (OpenPNE 3 parity), so a
 * locked-out account is reset from the CLI instead.
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

        $password = $this->resolveValidatedPassword($username);
        if ($password === null) {
            return self::FAILURE;
        }

        // The `password` cast hashes the plaintext on save.
        $admin->update(['password' => $password]);

        // Lockout recovery doubles as compromise recovery: end every existing session
        // and remember-me cookie so only the holder of the new password gets back in.
        SessionRevocation::revokeAdmin($admin);

        SecurityLog::event('password.changed', ['guard' => 'admin', 'username' => $username, 'via' => 'cli']);

        $this->info("Password for administrator [{$username}] has been reset and their sessions revoked.");

        return self::SUCCESS;
    }
}
