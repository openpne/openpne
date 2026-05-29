<?php

namespace App\Features\Diary;

use App\Models\Member;

/**
 * Restriction levels in ascending order (open=least restricted … private=most restricted).
 * Monotonic ordering makes access checks a single range comparison:
 *   diary.visibility <= clearanceFor(viewer, owner)
 *
 * Values 1/2/3 match OpenPNE 3 public_flag (SNS=1 / friend=2 / private=3) for upgrade fidelity.
 * Open=0 is an OpenPNE 4 invention; it is NOT a legacy public_flag value
 * (OpenPNE 3 stored web-public as public_flag=1 + is_open=true).
 * Open is defined here but not selectable in D1 (no guest routes yet).
 */
enum Visibility: int
{
    case Open = 0;
    case Members = 1;
    case Friends = 2;
    case Private = 3;

    /**
     * The highest restriction level the viewer is allowed to see on the owner's diaries.
     * owner → Private (sees everything)
     * friend → Friends
     * other member → Members
     * Guest clearance (Open) is not used in D1 (no auth-free routes).
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

    /** String slug for serialization. Never pass raw int to frontend. */
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
