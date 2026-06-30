<?php

namespace App\Features\Member\Actions;

use App\Models\EmailChangeRequest;
use App\Models\Member;
use App\Notifications\Member\EmailChangeConfirmationNotification;
use App\Notifications\Member\EmailChangeNoticeNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Issues an email-change confirmation token and mails it. The confirmation link goes to the proposed
 * NEW address (proving control of it); a notify-only security alert goes to the current/OLD address
 * (the member is still authenticated against it). members.email is not touched until confirmation.
 *
 * Both mails pin their address as an on-demand notifiable (Notification::route) rather than notifying
 * the Member: the notifications queue, and the queue worker resolves a Member notifiable's address at
 * send time — a fast confirmation could flip members.email first and misroute the old-address notice.
 */
class RequestEmailChange
{
    public function __invoke(Member $member, string $newEmail): void
    {
        $newEmail = Str::lower(trim($newEmail));

        // One row per member (the column is unique): a re-request refreshes the token in place. upsert
        // is a single atomic statement so two concurrent requests cannot race the unique index into a
        // 500.
        $raw = Str::random(40);
        EmailChangeRequest::upsert(
            [[
                'member_id' => $member->getKey(),
                'new_email' => $newEmail,
                'token' => hash('sha256', $raw),
                'created_at' => now(),
            ]],
            ['member_id'],
            ['new_email', 'token', 'created_at'],
        );

        Notification::route('mail', $newEmail)->notify(
            new EmailChangeConfirmationNotification($raw, app()->getLocale()),
        );

        // Sent to the current address while members.email still holds it (captured here as a literal).
        Notification::route('mail', $member->email)->notify(
            new EmailChangeNoticeNotification($newEmail, app()->getLocale()),
        );
    }
}
