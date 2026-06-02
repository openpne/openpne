<?php

namespace App\Support;

use App\Models\Member;

/**
 * Content visibility, shared across features (diaries, profile values, …).
 *
 * Levels are in ascending restriction (Open = least restricted … Private = most
 * restricted). The monotonic ordering makes an access check a single range comparison:
 *   content.visibility <= Visibility::clearanceFor($viewer, $owner)
 *
 * OpenPNE 3 stored visibility on a different (non-monotonic) public_flag scale; the upgrade
 * maps it here via fromOpenPne3PublicFlag() / the equivalent SQL CASE, so the whole app uses
 * this one representation. Open (web-public) is gated per field by is_public_web for profiles.
 */
enum Visibility: int
{
    case Open = 0;

    case Members = 1;

    case Friends = 2;

    case Private = 3;

    /**
     * The highest restriction level $viewer may see on $owner's content.
     * owner → Private (everything); friend → Friends; any other member → Members.
     * A guest (no Member) is handled by the caller, not here.
     */
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
     * Map an OpenPNE 3 public_flag (SNS=1, friend=2, private=3, web=4) to a concrete
     * Visibility. Anything else (including OpenPNE's invalid 0 default) falls back to
     * Members. For per-value upgrades the SQL CASE mirrors this but keeps NULL as "use the
     * field default" instead of collapsing to Members.
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
}
