<?php

namespace App\Features\Member\Actions;

use App\Models\EmailChangeRequest;
use App\Models\Member;
use App\Notifications\Member\EmailChangeConfirmationNotification;
use App\Notifications\Member\EmailChangeNoticeNotification;
use App\Support\SecurityLog;
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
        // 500. Two independent raw tokens: confirm (to the new address) and cancel (to the old).
        $raw = Str::random(40);
        $rawCancel = Str::random(40);
        EmailChangeRequest::upsert(
            [[
                'member_id' => $member->getKey(),
                'new_email' => $newEmail,
                'token' => hash('sha256', $raw),
                'cancel_token' => hash('sha256', $rawCancel),
                'created_at' => now(),
            ]],
            ['member_id'],
            ['new_email', 'token', 'cancel_token', 'created_at'],
        );

        // The pending change is durable; log before the fallible notification sends. The new address
        // is the subject of the change, so it is logged (contrast: passwords never are).
        SecurityLog::event('email.change_requested', [
            'guard' => 'member',
            'member_id' => $member->getKey(),
            'new_email' => $newEmail,
        ]);

        Notification::route('mail', $newEmail)->notify(
            new EmailChangeConfirmationNotification($raw, (int) $member->getKey(), app()->getLocale()),
        );

        // Sent to the current address while members.email still holds it (captured here as a literal).
        // Carries the cancel link so the old-address holder can void a change they did not initiate.
        Notification::route('mail', $member->email)->notify(
            new EmailChangeNoticeNotification($newEmail, $rawCancel, app()->getLocale()),
        );
    }
}
