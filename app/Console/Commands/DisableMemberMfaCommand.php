<?php

namespace App\Console\Commands;

use App\Auth\SessionRevocation;
use App\Models\Member;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

/**
 * Lockout recovery for a member who lost both the authenticator device and the recovery codes:
 * clears the TOTP secret and recovery codes so they can sign in with their password alone and
 * set MFA up again. A member does have a self-service password reset, but that flow cannot
 * remove a lost second factor — only server access (the operator trust boundary) can.
 */
class DisableMemberMfaCommand extends Command
{
    protected $signature = 'openpne:member:disable-mfa {email : The member email address}';

    protected $description = "Disable a member's two-factor authentication (lockout recovery)";

    public function handle(DisableTwoFactorAuthentication $disable): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        $member = Member::where('email', $email)->first();
        if ($member === null) {
            $this->error("Member [{$email}] not found.");

            return self::FAILURE;
        }

        // All-or-nothing: clearing the factor and revoking sessions must not half-apply.
        DB::transaction(function () use ($disable, $member): void {
            $disable($member);

            // Removing a factor is a credential change: end every session so a stolen one cannot
            // outlive the reset. The operator is at the CLI, not in a browser, so revoke all.
            SessionRevocation::revokeMember($member);
        });

        $this->info("Two-factor authentication for member [{$email}] has been disabled and their sessions revoked.");

        return self::SUCCESS;
    }
}
