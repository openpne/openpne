<?php

namespace App\Features\Profile;

use App\Models\Member;
use App\Services\SnsSettingService;
use App\Support\PreferenceKey;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Illuminate\Validation\Rules\Enum;

/**
 * The audiences a member may choose for who sees their age. Single source for the config-form
 * options, the request validation rule, and VisibleAge's web-public gate, so they cannot drift.
 *
 * Web-public (Open) is offered only while the SNS allows it (SnsSettingKey::AllowWebPublicAge,
 * OpenPNE 3 is_allow_web_public_flag_age, default off) — mirroring DiaryVisibility's web-public
 * gate, but the SNS setting (not a config flag) so an OpenPNE 3 site's choice carries over.
 */
final class AgeVisibility
{
    /** @return list<Visibility> */
    public static function options(): array
    {
        $webPublic = self::allowsWebPublic() ? [Visibility::Open] : [];

        return [...$webPublic, Visibility::Members, Visibility::Friends, Visibility::Private];
    }

    /**
     * The audience to pre-select for $member: their stored AgeVisibility (default Private), clamped
     * to the currently selectable audiences — so a stored Open pre-selects as Members once
     * web-public age is off (it conveys no visibility then; see VisibleAge).
     */
    public static function defaultFor(Member $member): Visibility
    {
        $preferred = $member->preference(PreferenceKey::AgeVisibility);

        return in_array($preferred, self::options(), true) ? $preferred : Visibility::Members;
    }

    /** Validation rule restricting age visibility to the selectable audiences. */
    public static function rule(): Enum
    {
        $rule = new Enum(Visibility::class);

        return self::allowsWebPublic() ? $rule : $rule->except([Visibility::Open]);
    }

    /** Whether the SNS lets members make their age visible to web guests. */
    public static function allowsWebPublic(): bool
    {
        return (bool) app(SnsSettingService::class)->get(SnsSettingKey::AllowWebPublicAge);
    }
}
