<?php

namespace App\Features\Diary;

use App\Models\Member;
use App\Support\PreferenceKey;
use App\Support\Visibility;
use Illuminate\Validation\Rules\Enum;

/**
 * The audiences a member may choose when posting or editing a diary. Single source for the
 * form options and the request validation rule so the two cannot drift: both honour the
 * openpne.diary.allow_web_public gate (OpenPNE 3 op_diary_plugin_use_open_diary).
 */
final class DiaryVisibility
{
    /**
     * Selectable audiences, web-public first when enabled (OpenPNE 3 lists it first). Members
     * stays the form default regardless, so enabling web-public never changes the default.
     *
     * @return list<Visibility>
     */
    public static function options(): array
    {
        $webPublic = self::allowsWebPublic() ? [Visibility::Open] : [];

        return [...$webPublic, Visibility::Members, Visibility::Friends, Visibility::Private];
    }

    /**
     * The audience to pre-select on the new-diary form for $member: their stored
     * DiaryDefaultVisibility (OpenPNE 3's per-member default), clamped to the currently
     * selectable audiences so a stored Open never pre-selects once web-public is turned off.
     */
    public static function defaultFor(Member $member): Visibility
    {
        $preferred = $member->preference(PreferenceKey::DiaryDefaultVisibility);

        return in_array($preferred, self::options(), true) ? $preferred : Visibility::Members;
    }

    /** Validation rule restricting visibility to the selectable audiences. */
    public static function rule(): Enum
    {
        $rule = new Enum(Visibility::class);

        return self::allowsWebPublic() ? $rule : $rule->except([Visibility::Open]);
    }

    private static function allowsWebPublic(): bool
    {
        return (bool) config('openpne.diary.allow_web_public');
    }
}
