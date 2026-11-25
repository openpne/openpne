<?php

namespace App\Console\Commands;

use App\Auth\AdminPasswords;
use App\Console\Commands\Concerns\ResolvesAdminPassword;
use App\Models\AdminUser;
use App\Support\SecurityLog;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * The panel only lets an administrator change their own password (OpenPNE 3 parity), so a
 * locked-out account is reset from the CLI instead.
 */
class ResetAdminPasswordCommand extends Command
{
    use ResolvesAdminPassword;

    protected $signature = 'openpne:admin:reset-password {username : The administrator username}';

    protected $description = "Reset an administrator's password (lockout recovery)";

    public function handle(AdminPasswords $passwords): int
    {
        $username = Str::trim((string) $this->argument('username'));

        $admin = AdminUser::where('username', $username)->first();
        if ($admin === null) {
            $this->error("Administrator [{$username}] not found.");

            return self::FAILURE;
        }

        $password = $this->resolveValidatedPassword($username);
        if ($password === null) {
            return self::FAILURE;
        }

        // Lockout recovery doubles as compromise recovery: every session, remember-me cookie and
        // unused setup link ends, so only the holder of the new password gets back in.
        $passwords->change($admin, $password);

        SecurityLog::event('password.changed', ['guard' => 'admin', 'username' => $username, 'via' => 'cli']);

        $this->info("Password for administrator [{$username}] has been reset and their sessions revoked.");

        return self::SUCCESS;
    }
}
