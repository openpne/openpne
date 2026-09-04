<?php

namespace App\Support;

use App\Models\Member;

/**
 * Levels ascend in restriction, so an access check is one range comparison: content.visibility <=
 * clearanceFor($viewer, $owner). The OpenPNE 3 public_flag scale is not monotonic; the upgrade maps it
 * through fromOpenPne3PublicFlag() and an equivalent SQL CASE.
 */
enum Visibility: int
{
    case Open = 0;

    case Members = 1;

    case Friends = 2;

    case Private = 3;

    /** A guest (no Member) is the caller's case, not handled here. */
    public static function clearanceFor(Member $viewer, Member $owner): self
    {
        if ($viewer->is($owner)) {
            return self::Private;
        }

        if ($viewer->isFriendsWith($owner)) {
            return self::Friends;
        }

        return self::Members;
    }

    /**
     * OpenPNE 3 public_flag: SNS=1, friend=2, private=3, web=4; anything else, including the invalid 0
     * default, is Members. The per-value upgrade's SQL CASE keeps NULL as "use the field default" instead.
     */
    public static function fromOpenPne3PublicFlag(?int $publicFlag): self
    {
        return match ($publicFlag) {
            4 => self::Open,
            2 => self::Friends,
            3 => self::Private,
            default => self::Members,
        };
    }

    /** String slug for serialization. Never pass raw int to the frontend. */
    public function slug(): string
    {
        return match ($this) {
            self::Open => 'open',
            self::Members => 'members',
            self::Friends => 'friends',
            self::Private => 'private',
        };
    }

    /**
     * Human-readable label key, translated via __()/t() on either surface. The set reads as
     * uniform audience nouns — "Anyone on the web" names the web explicitly so it cannot be
     * confused with "All members" (ja: Web全体 vs メンバー全員).
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Anyone on the web',
            self::Members => 'All members',
            self::Friends => '%Friends% only',
            self::Private => 'Private',
        };
    }
}
