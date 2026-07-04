<?php

namespace App\Console\Commands;

use App\Auth\SessionRevocation;
use App\Models\AdminUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Lockout recovery for a lost authenticator device: clears an administrator's TOTP secret
 * and recovery codes so they can sign in with their password alone and set MFA up again. A
 * CLI action gated by server access — the same trust boundary as the password reset — because
 * an admin has no email, so there is no self-service reset path.
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

        // All-or-nothing: clearing the factor and revoking sessions must not half-apply.
        DB::transaction(function () use ($admin): void {
            $admin->saveAppAuthenticationSecret(null);
            $admin->saveAppAuthenticationRecoveryCodes(null);

            // Removing a factor is a credential change: end every session so a stolen one cannot
            // outlive the reset. The operator is at the CLI, not in a browser, so revoke all.
            SessionRevocation::revokeAdmin($admin);
        });

        $this->info("Two-factor authentication for administrator [{$username}] has been disabled and their sessions revoked.");

        return self::SUCCESS;
    }
}
