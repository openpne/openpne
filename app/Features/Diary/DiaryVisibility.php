<?php

namespace App\Features\Diary;

use App\Models\Member;
use App\Support\PreferenceKey;
use App\Support\Visibility;
use App\Support\VisibilityChoices;
use Illuminate\Validation\Rules\Enum;

/**
 * The audiences a member may choose when posting or editing a diary. Single source for the
 * form options and the request validation rule so the two cannot drift: both honour the
 * openpne.diary.allow_web_public gate (OpenPNE 3 op_diary_plugin_use_open_diary) and the friend
 * unit's state (VisibilityChoices).
 */
final class DiaryVisibility
{
    /**
     * Selectable audiences, web-public first when enabled (OpenPNE 3 lists it first). Members
     * stays the form default regardless, so enabling web-public never changes the default.
     *
     * @param  Visibility|null  $current  the edited diary's stored audience (null when composing)
     * @return list<Visibility>
     */
    public static function options(?Visibility $current = null): array
    {
        return VisibilityChoices::offered(self::allowsWebPublic(), $current);
    }

    /**
     * The audience to pre-select on the new-diary form for $member: their stored
     * DiaryDefaultVisibility, clamped to the currently
     * selectable audiences so a stored Open never pre-selects once web-public is turned off.
     *
     * The preference seeds new entries, so it takes no sticky current: while friends are off a
     * stored Friends pre-selects as Members, which the member sees in the select before posting.
     * The preference row itself is left alone until they save the setting.
     */
    public static function defaultFor(Member $member): Visibility
    {
        $preferred = $member->preference(PreferenceKey::DiaryDefaultVisibility);

        return in_array($preferred, self::options(), true) ? $preferred : Visibility::Members;
    }

    /** Validation rule restricting visibility to the audiences options() offers. */
    public static function rule(?Visibility $current = null): Enum
    {
        return VisibilityChoices::rule(self::options($current));
    }

    /**
     * Whether the SNS serves web-public (Open) diaries at all. Read by the read path too
     * (DiaryAccess / DiaryVisibilityScope): turning the gate off must hide entries already
     * stored as Open from guests, not merely stop new ones being written.
     */
    public static function allowsWebPublic(): bool
    {
        return (bool) config('openpne.diary.allow_web_public');
    }
}
