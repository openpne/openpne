<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Member;
use App\Services\SnsSettingService;
use Illuminate\Http\Request;

/**
 * URLs carry no look, so every consumer resolves it here and the shell ships the answer as one
 * shared prop (docs/internals/looks.md).
 */
final class LookResolver
{
    public static function resolve(Request $request): Look
    {
        // A guest gets standard, not the site default: a look's serializer needs a viewer to render against.
        $member = $request->user('member');
        if (! $member instanceof Member) {
            return Look::Standard;
        }

        // With fewer than two looks on offer nothing below could resolve to anything but the
        // default (the choice is filtered through the same set), so a site that has not opted in
        // skips the preference read — and its cost — entirely.
        if (count(self::selectable()) < 2) {
            return self::siteDefault();
        }

        // A stored look the site no longer offers is ignored, the belt for a row the admin save's cleanup has not reached.
        $chosen = $member->preferredLook();
        if ($chosen !== null && in_array($chosen, self::selectable(), true)) {
            return $chosen;
        }

        return self::siteDefault();
    }

    /**
     * The looks the administrator offers plus the site default, which is always pickable; the one
     * derivation point of the effective set.
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
