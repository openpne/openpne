<?php

namespace App\Features\Auth\Actions;

use App\Features\Auth\RegistrationTokenSource;
use App\Models\Member;
use App\Models\RegistrationToken;
use App\Notifications\Auth\RegistrationLinkNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Issues an email-confirmation registration token and mails its link. Enumeration-safe: if the
 * address already belongs to a Member it returns AlreadyMember without issuing or mailing, so the
 * self-service screen (which ignores the result) cannot reveal whether an address is registered. The
 * email is normalized here (Fortify's lowercase_usernames is applied by its own controller, which
 * this flow bypasses) and the token's email is authoritative from here on.
 *
 * A token may originate from self-service, a member invite, or an admin invite; `source` and the
 * member-invite `inviter` are stored so completion can both re-check the mode and auto-friend.
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

        // One row per email (the column is unique): a re-request refreshes the token in place. upsert
        // is a single atomic statement so two concurrent first requests cannot race the unique index
        // into a 500 and break the neutral-response contract. source/inviter_id are written on every
        // issuance (last-writer-wins) so a self re-request never inherits a prior invite's provenance.
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
