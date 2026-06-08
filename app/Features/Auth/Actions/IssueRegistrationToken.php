<?php

namespace App\Features\Auth\Actions;

use App\Models\Member;
use App\Models\RegistrationToken;
use App\Notifications\Auth\RegistrationLinkNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Issues an email-confirmation registration token and mails its link. Enumeration-safe: if the
 * address already belongs to a Member it returns silently, so the email-entry screen cannot reveal
 * whether an address is registered. The email is normalized here (Fortify's lowercase_usernames is
 * applied by its own controller, which this flow bypasses) and the token's email is authoritative
 * from here on.
 */
class IssueRegistrationToken
{
    public function __invoke(string $email): void
    {
        $email = Str::lower(trim($email));

        if (Member::where('email', $email)->exists()) {
            return;
        }

        // One row per email (the column is unique): a re-request refreshes the token in place.
        $raw = Str::random(40);
        RegistrationToken::updateOrCreate(
            ['email' => $email],
            ['token' => hash('sha256', $raw), 'created_at' => now()],
        );

        Notification::route('mail', $email)->notify(
            new RegistrationLinkNotification($raw, app()->getLocale()),
        );
    }
}
