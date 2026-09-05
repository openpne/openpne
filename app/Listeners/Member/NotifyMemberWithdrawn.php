<?php

namespace App\Listeners\Member;

use App\Features\Member\Events\MemberWithdrawn;
use App\Notifications\Member\WithdrawalAdminNotification;
use App\Notifications\Member\WithdrawalCompletedNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Sent for every withdrawal path, self-service and admin-initiated alike, a receipt being owed to
 * the address holder whoever pulled the trigger (docs/internals/notifications.md, "Gating flow").
 */
class NotifyMemberWithdrawn
{
    public function handle(MemberWithdrawn $event): void
    {
        // An upgraded OpenPNE 3 member may have no address (`members.email` is nullable, captured as
        // ''), and OpenPNE 3 likewise only mailed the receipt when one was present.
        if ($event->email !== '') {
            Notification::route('mail', $event->email)->notify(
                new WithdrawalCompletedNotification($event->name, $event->locale),
            );
        }

        // An AI account leaving is its owner tidying up, not a member leaving the site, so the operator
        // notice would be noise.
        if ($event->wasAiAccount) {
            return;
        }

        Notification::route('mail', sns_admin_mail_address())->notify(
            new WithdrawalAdminNotification($event->name, $event->email, $event->memberId, config('app.locale')),
        );
    }
}
