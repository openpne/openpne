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
 * The confirmation goes to the proposed address and the notice to the current one; `members.email` is
 * not touched until confirmation. Both pin their address as an on-demand notifiable: the notifications
 * queue, and resolving a Member notifiable at send time could misroute the notice.
 */
class RequestEmailChange
{
    public function __invoke(Member $member, string $newEmail): void
    {
        $newEmail = Str::lower(trim($newEmail));

        // One row per member: `upsert` is atomic, so a re-request refreshes the token in place and two
        // concurrent requests cannot race the unique index.
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

        // Logged before the fallible sends, and with the new address, which is the change's subject.
        SecurityLog::event('email.change_requested', [
            'guard' => 'member',
            'member_id' => $member->getKey(),
            'new_email' => $newEmail,
        ]);

        Notification::route('mail', $newEmail)->notify(
            new EmailChangeConfirmationNotification($raw, (int) $member->getKey(), app()->getLocale()),
        );

        Notification::route('mail', $member->email)->notify(
            new EmailChangeNoticeNotification($newEmail, $rawCancel, app()->getLocale()),
        );
    }
}
