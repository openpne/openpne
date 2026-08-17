<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Member;
use App\Services\SnsSettingService;
use Illuminate\Http\Request;

/**
 * Decides which App\Support\Look a request renders in. URLs carry no look — it is an attribute of
 * the viewer — so every consumer asks here and the shell ships the answer as one shared prop.
 * The chain is guest clamp → session preview → durable member choice → site default
 * (docs/internals/looks.md).
 */
final class LookResolver
{
    /**
     * Where the look a member is trying on is kept, in the member-realm session:
     * `['look' => <id>, 'pin' => <bool>]`, `pin` false meaning "following the site default".
     */
    public const PREVIEW_SESSION_KEY = 'look_preview';

    public static function resolve(Request $request): Look
    {
        // A look is a member's way around their own pages, and a signed-out visitor reaches none of
        // them — so the site default does not answer for a guest. First, because a signed-out
        // request may carry a warm preview session from whoever used this browser before.
        $member = $request->user('member');
        if (! $member instanceof Member) {
            return Look::Standard;
        }

        // With fewer than two looks on offer nothing below could resolve to anything but the
        // default (preview and choice are both filtered through the same set), so a site that has
        // not opted in skips the preference read — and its cost — entirely.
        if (count(self::selectable()) < 2) {
            return self::siteDefault();
        }

        // A preview outranks the durable choice: it is what the member asked to see right now, and
        // it lasts until they confirm, cancel, cross to Classic, or sign out.
        $preview = self::preview($request);
        if ($preview !== null) {
            return $preview;
        }

        // A stored look the site no longer offers is ignored rather than honoured. The real defense
        // is the admin save's cleanup; this is the belt for a row it has not reached yet.
        $chosen = $member->preferredLook();
        if ($chosen !== null && in_array($chosen, self::selectable(), true)) {
            return $chosen;
        }

        return self::siteDefault();
    }

    /**
     * The look being tried on, or null when there is none to render: no session entry, or one naming
     * a look the site no longer offers. Says nothing about who is asking — the guest clamp lives in
     * resolve() alone, so that ordering stays the one thing keeping a signed-out request (which may
     * carry the previous member's session) on the standard layout.
     */
    public static function preview(Request $request): ?Look
    {
        $intent = self::previewIntent($request);
        if ($intent === null) {
            return null;
        }

        if (! in_array($intent['look'], self::selectable(), true)) {
            // Dropped, not merely ignored: a session entry that outlived its look would wake the
            // preview bar back up if the administrator ever re-offers it, with no action from the
            // member. Once unrenderable, the trial is over.
            $request->session()->forget(self::PREVIEW_SESSION_KEY);

            return null;
        }

        return $intent['look'];
    }

    /**
     * What the session says the member asked for, unfiltered: `pin` true = they picked this look,
     * false = they picked "follow the site default" and are seeing whatever that currently is. The
     * one place the session shape is read, so the preview bar and the POST that confirms it cannot
     * disagree about what was chosen. The confirm path checks the look against selectable() itself
     * (and refuses), where the render path above just drops it.
     *
     * @return array{look: Look, pin: bool}|null
     */
    public static function previewIntent(Request $request): ?array
    {
        // A stateless realm (no session bound) has nowhere to have parked a preview.
        if (! $request->hasSession()) {
            return null;
        }

        $stored = $request->session()->get(self::PREVIEW_SESSION_KEY);
        if (! is_array($stored) || ! is_string($stored['look'] ?? null)) {
            return null;
        }

        $look = Look::tryFrom($stored['look']);

        return $look === null ? null : ['look' => $look, 'pin' => (bool) ($stored['pin'] ?? false)];
    }

    /**
     * The looks a member may pick from: the ones the administrator offers, plus the site default —
     * which is always among them, being what "follow the site default" follows. THE single
     * derivation point of the effective set; resolver, serializer, form requests and the admin
     * cleanup all read it from here.
     *
     * @return list<Look>
     */
    public static function selectable(): array
    {
        $settings = app(SnsSettingService::class);
        $stored = $settings->get(SnsSettingKey::SelectableLooks);
        assert(is_array($stored));

        return self::selectableAmong($stored, self::siteDefault());
    }

    /**
     * The same union over values that are not stored yet — the admin page computing the set its own
     * save is about to establish. Sharing this with selectable() is what keeps one definition of
     * "effective set" behind both the read path and the cleanup.
     *
     * @param  list<Look>  $offered
     * @return list<Look>
     */
    public static function selectableAmong(array $offered, Look $default): array
    {
        // The key's codec already dedupes and orders by the registry, so the union has one shape.
        /** @var list<Look> $set */
        $set = SnsSettingKey::SelectableLooks->coerce([...$offered, $default]);

        return $set;
    }

    public static function siteDefault(): Look
    {
        return app(SnsSettingService::class)->get(SnsSettingKey::DefaultLook);
    }
}
