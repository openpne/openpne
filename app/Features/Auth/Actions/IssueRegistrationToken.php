<?php

namespace App\Features\Auth\Actions;

use App\Features\Auth\RegistrationTokenSource;
use App\Models\Member;
use App\Models\RegistrationToken;
use App\Notifications\Auth\RegistrationLinkNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * A known address returns AlreadyMember without issuing or mailing, so a caller that shows the same
 * screen either way reveals nothing about which addresses are registered. The email is lowercased
 * here because Fortify's lowercase_usernames runs only in Fortify's own controller, which this flow
 * bypasses.
 */
class IssueRegistrationToken
{
    public function __invoke(
        string $email,
        RegistrationTokenSource $source = RegistrationTokenSource::Selfservice,
        ?Member $inviter = null,
        ?string $message = null,
    ): IssueResult {
        $email = Str::lower(trim($email));

        // Case-insensitive so the no-op holds on any collation: members.email is lowercased on the
        // app's own creation paths, but an upgraded row can be verbatim mixed-case, and a
        // case-sensitive store (SQLite/PostgreSQL) would otherwise miss it and leak a token + mail.
        if (Member::whereRaw('lower(email) = ?', [$email])->exists()) {
            return IssueResult::AlreadyMember;
        }

        // A single atomic upsert, so two concurrent first requests cannot race the unique index into a
        // 500, and source/inviter_id are overwritten on every issuance so a self re-request never
        // inherits a prior invite's provenance.
        $raw = Str::random(40);
        RegistrationToken::upsert(
            [[
                'email' => $email,
                'token' => hash('sha256', $raw),
                'source' => $source->value,
                'inviter_id' => $inviter?->getKey(),
                'created_at' => now(),
            ]],
            ['email'],
            ['token', 'source', 'inviter_id', 'created_at'],
        );

        Notification::route('mail', $email)->notify(
            new RegistrationLinkNotification($raw, app()->getLocale(), $source, $inviter?->name, $message),
        );

        return IssueResult::Issued;
    }
}
