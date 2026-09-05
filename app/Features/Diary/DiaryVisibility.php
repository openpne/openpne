<?php

namespace App\Features\Diary;

use App\Models\Member;
use App\Services\SnsSettingService;
use App\Support\PreferenceKey;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use App\Support\VisibilityChoices;
use Illuminate\Validation\Rules\Enum;

/**
 * Single source for the form options and the request validation rule so the two cannot drift: both
 * honour the SnsSettingKey::DiaryAllowWebPublic gate (OpenPNE 3 op_diary_plugin_use_open_diary) and
 * the friend unit's state.
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
     * The member's stored DiaryDefaultVisibility, clamped to the currently selectable audiences so a
     * stored Open never pre-selects once web-public is off. The clamp leaves the preference row
     * alone: it seeds new entries, so a stored Friends simply pre-selects as Members while friends
     * are off.
     */
    public static function defaultFor(Member $member): Visibility
    {
        $preferred = $member->preference(PreferenceKey::DiaryDefaultVisibility);

        return in_array($preferred, self::options(), true) ? $preferred : Visibility::Members;
    }

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
        return (bool) app(SnsSettingService::class)->get(SnsSettingKey::DiaryAllowWebPublic);
    }
}
