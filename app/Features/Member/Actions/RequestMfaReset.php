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
 * Admin-initiated: issue a two-factor reset link and mail it to the member's registered address. The link
 * lets the locked-out member (a guest — they cannot present a second-factor proof) clear their factor by
 * entering their account password (App\Features\Member\MfaResetLinkController, ConsumeMfaReset). The admin
 * never gains a takeover ability: the link goes only to the member's mailbox and needs the member's
 * password to act — both outside the admin's reach (TASK-122, docs/internals/security.md).
 *
 * The live-factor + registered-address preconditions are re-checked under a row lock, not trusted from the
 * Filament action's visibility snapshot: a factor disabled or an address cleared between render and click
 * must not mint a link for a member who cannot use it. The Filament layer halts gracefully on the stale
 * state (UX); reaching this Action with the precondition already broken is a caller bug, so it throws.
 * Global lock order is Member → mfa_reset_requests, shared with ForceDisableMemberMfa / ConsumeMfaReset.
 */
class RequestMfaReset
{
    public function __invoke(Member $member): void
    {
        // One row per member (the column is unique): a re-send refreshes the token in place, killing the
        // old link. upsert is a single atomic statement so two concurrent clicks cannot race the unique
        // index into a 500.
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

        // The pending link is durable; log before the fallible notification send. The token is never logged.
        SecurityLog::event('mfa.reset_link_sent', [
            'guard' => 'member',
            'member_id' => $member->getKey(),
            'admin_username' => auth('admin')->user()?->username,
        ]);

        // Pinned to the registered address as an on-demand notifiable (not the Member): the notification
        // queues, and resolving a Member notifiable's address at send time could misroute a link if the
        // address changed meanwhile (RequestEmailChange's reasoning).
        Notification::route('mail', $email)->notify(new MfaResetLinkNotification($raw, $locale));
    }
}
