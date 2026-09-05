<?php

namespace App\Console\Commands;

use App\Auth\SessionRevocation;
use App\Models\AdminUser;
use App\Support\SecurityLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * See docs/internals/security.md "Admin two-factor authentication".
 */
class DisableAdminMfaCommand extends Command
{
    protected $signature = 'openpne:admin:disable-mfa {username : The administrator username}';

    protected $description = "Disable an administrator's two-factor authentication (lockout recovery)";

    public function handle(): int
    {
        $username = trim((string) $this->argument('username'));

        $admin = AdminUser::where('username', $username)->first();
        if ($admin === null) {
            $this->error("Administrator [{$username}] not found.");

            return self::FAILURE;
        }

        // Read before clearing: only a live factor's removal is an event to log.
        $wasEnabled = filled($admin->getAppAuthenticationSecret());

        // All-or-nothing: clearing the factor and revoking sessions must not half-apply.
        DB::transaction(function () use ($admin): void {
            $admin->saveAppAuthenticationSecret(null);
            $admin->saveAppAuthenticationRecoveryCodes(null);

            // There is no current session to keep at the CLI, so every one of the admin's goes.
            SessionRevocation::revokeAdmin($admin);
        });

        if ($wasEnabled) {
            SecurityLog::event('mfa.disabled', ['guard' => 'admin', 'username' => $username, 'via' => 'cli']);
        }

        $this->info("Two-factor authentication for administrator [{$username}] has been disabled and their sessions revoked.");

        return self::SUCCESS;
    }
}
