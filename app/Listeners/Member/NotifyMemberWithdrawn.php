<?php

namespace App\Listeners\Member;

use App\Features\Member\Events\MemberWithdrawn;
use App\Notifications\Member\WithdrawalAdminNotification;
use App\Notifications\Member\WithdrawalCompletedNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Two mails per withdrawal, both to on-demand addresses (the Member row is gone): a receipt to the
 * former member and a notice to the site admin. Sent for every withdrawal path — self-service and
 * admin-initiated alike — since a withdrawal receipt is owed to the address holder regardless of who
 * pulled the trigger.
 */
class NotifyMemberWithdrawn
{
    public function handle(MemberWithdrawn $event): void
    {
        // A member upgraded from OpenPNE 3 without a usable address is login-impossible and has no
        // inbox (members.email is nullable → captured as ''); OpenPNE 3 likewise only mailed the
        // receipt when an address was present. Skip the receipt, but still notify the admin.
        if ($event->email !== '') {
            Notification::route('mail', $event->email)->notify(
                new WithdrawalCompletedNotification($event->name, $event->locale),
            );
        }

        // Operator-facing: rendered in the site default locale, not the withdrawing member's.
        Notification::route('mail', sns_admin_mail_address())->notify(
            new WithdrawalAdminNotification($event->name, $event->email, $event->memberId, config('app.locale')),
        );
    }
}
