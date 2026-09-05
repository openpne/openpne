<?php

namespace App\Features\Member\Actions;

use App\Models\Member;
use App\Models\MfaResetRequest;
use App\Notifications\Member\MfaResetLinkNotification;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * The live-factor and registered-address preconditions are re-checked under the row lock, not trusted
 * from the Filament action's snapshot; reaching here with one already broken is a caller bug and
 * throws. Issuing a link gives the admin no takeover ability (docs/internals/security.md, "Member
 * two-factor authentication").
 */
class RequestMfaReset
{
    public function __invoke(Member $member): void
    {
        // One row per member: `upsert` is atomic, so a re-send refreshes the token in place and two
        // concurrent clicks cannot race the unique index.
        $raw = Str::random(40);

        [$email, $locale] = DB::transaction(function () use ($member, $raw): array {
            $fresh = Member::whereKey($member->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->hasEnabledTwoFactorAuthentication() || blank($fresh->email)) {
                throw new MfaResetUnavailable('A two-factor reset link requires a member with a live factor and a registered address.');
            }

            MfaResetRequest::upsert(
                [[
                    'member_id' => $fresh->getKey(),
                    'token' => hash('sha256', $raw),
                    'created_at' => now(),
                ]],
                ['member_id'],
                ['token', 'created_at'],
            );

            // The address the lock just verified — not the caller's snapshot, which a concurrent
            // email change could have left stale.
            return [(string) $fresh->email, $fresh->locale ?? app()->getLocale()];
        });

        // Logged before the fallible send, and never with the token.
        SecurityLog::event('mfa.reset_link_sent', [
            'guard' => 'member',
            'member_id' => $member->getKey(),
            'admin_username' => auth('admin')->user()?->username,
        ]);

        // Pinned to the address as an on-demand notifiable: the notification queues, and resolving a
        // Member notifiable at send time could misroute the link if the address changed meanwhile.
        Notification::route('mail', $email)->notify(new MfaResetLinkNotification($raw, $locale));
    }
}
