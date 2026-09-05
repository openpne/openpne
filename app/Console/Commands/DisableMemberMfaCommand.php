<?php

namespace App\Console\Commands;

use App\Features\Member\Actions\ForceDisableMemberMfa;
use App\Models\Member;
use App\Notifications\Member\MfaDisabledNotification;
use App\Support\SecurityLog;
use Illuminate\Console\Command;

/**
 * See docs/internals/security.md, "Member two-factor authentication".
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

        // The Action returns whether a live factor was removed; clearing a pending set-up is not one.
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
