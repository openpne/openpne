<?php

namespace App\Features\Profile;

use App\Models\Member;
use App\Support\PreferenceKey;
use App\Support\Visibility;
use Illuminate\Validation\Rules\Enum;

/**
 * The audiences a member may choose for who sees their age. Single source for the config-form
 * options and the request validation rule so the two cannot drift.
 *
 * Unlike DiaryVisibility, age has no web-public choice: VisibleAge fail-closes guests (OpenPNE 3
 * gates web-public age behind is_allow_web_public_flag_age, default off, with no OpenPNE 4
 * equivalent yet), so Open would be a choice that never affects anyone — it is excluded from both
 * the options and the rule.
 */
final class AgeVisibility
{
    /** @return list<Visibility> */
    public static function options(): array
    {
        return [Visibility::Members, Visibility::Friends, Visibility::Private];
    }

    /**
     * The audience to pre-select for $member: their stored AgeVisibility (default Private). A
     * stored Open (upgraded from an OpenPNE 3 web-public age) is not offered, and among non-guests
     * it already behaves as Members (VisibleAge fail-closes guests), so it pre-selects as Members.
     */
    public static function defaultFor(Member $member): Visibility
    {
        $preferred = $member->preference(PreferenceKey::AgeVisibility);

        return in_array($preferred, self::options(), true) ? $preferred : Visibility::Members;
    }

    /** Validation rule restricting age visibility to the selectable audiences (no web-public). */
    public static function rule(): Enum
    {
        return (new Enum(Visibility::class))->except([Visibility::Open]);
    }
}
