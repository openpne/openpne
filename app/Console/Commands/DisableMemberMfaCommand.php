<?php

namespace App\Console\Commands;

use App\Features\Member\Actions\ForceDisableMemberMfa;
use App\Models\Member;
use App\Notifications\Member\MfaDisabledNotification;
use App\Support\SecurityLog;
use Illuminate\Console\Command;

/**
 * Lockout recovery for a member who lost both the authenticator device and the recovery codes:
 * clears the TOTP secret and recovery codes so they can sign in with their password alone and
 * set MFA up again. A member does have a self-service password reset, but that flow cannot
 * remove a lost second factor — only server access (the operator trust boundary) can. The
 * admin panel's "send a reset link" action (App\Filament, ConsumeMfaReset) is the delegable
 * alternative that keeps the account-password proof in the member's hands.
 */
class DisableMemberMfaCommand extends Command
{
    protected $signature = 'openpne:member:disable-mfa {email : The member email address}';

    protected $description = "Disable a member's two-factor authentication (lockout recovery)";

    public function handle(ForceDisableMemberMfa $forceDisable): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        $member = Member::where('email', $email)->first();
        if ($member === null) {
            $this->error("Member [{$email}] not found.");

            return self::FAILURE;
        }

        // The shared core clears the factor, revokes every session, and drops any pending reset link
        // in one transaction; it returns whether a LIVE factor was removed — only that is a credential
        // change worth mailing the member about (clearing a pending set-up, or a member with none, is not).
        $wasEnabled = $forceDisable($member);

        if ($wasEnabled) {
            // Log before the alert: the fallible enqueue must not suppress the audit record.
            SecurityLog::event('mfa.disabled', ['guard' => 'member', 'member_id' => $member->getKey(), 'via' => 'cli']);
            $member->notify(new MfaDisabledNotification($member->locale ?? config('app.locale')));
        }

        $this->info("Two-factor authentication for member [{$email}] has been disabled and their sessions revoked.");

        return self::SUCCESS;
    }
}
