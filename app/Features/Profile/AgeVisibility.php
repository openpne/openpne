<?php

namespace App\Features\Profile;

use App\Models\Member;
use App\Services\SnsSettingService;
use App\Support\PreferenceKey;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use App\Support\VisibilityChoices;
use Illuminate\Validation\Rules\Enum;

/**
 * The audiences a member may choose for who sees their age. Single source for the config-form
 * options, the request validation rule, and VisibleAge's web-public gate, so they cannot drift.
 *
 * Web-public (Open) is offered only while the SNS allows it (SnsSettingKey::AllowWebPublicAge,
 * OpenPNE 3 is_allow_web_public_flag_age, default off) — mirroring DiaryVisibility's web-public
 * gate, but the SNS setting (not a config flag) so an OpenPNE 3 site's choice carries over.
 *
 * Unlike the diary default, this setting is not a seed for new content but the live audience of an
 * attribute the member already has, and the profile form re-posts it on every save — so the
 * member-scoped pair (optionsFor / ruleFor) keeps a stored Friends offered while friends are off,
 * and every call site takes it rather than passing a current of its own.
 */
final class AgeVisibility
{
    /** @return list<Visibility> */
    public static function options(?Visibility $current = null): array
    {
        return VisibilityChoices::offered(self::allowsWebPublic(), $current);
    }

    /**
     * The audiences offered to $member, which is what defaultFor() pre-selects among.
     *
     * @return list<Visibility>
     */
    public static function optionsFor(Member $member): array
    {
        return self::options(self::defaultFor($member));
    }

    /**
     * The audience to pre-select for $member: their stored AgeVisibility (default Private), clamped
     * to the audiences the setter offers them — so a stored Open pre-selects as Members once
     * web-public age is off (it conveys no visibility then; see VisibleAge), while a stored Friends
     * survives the friend unit going off (clamping it would widen the age to every member).
     */
    public static function defaultFor(Member $member): Visibility
    {
        $preferred = $member->preference(PreferenceKey::AgeVisibility);

        return in_array($preferred, self::options($preferred), true) ? $preferred : Visibility::Members;
    }

    /** Validation rule restricting age visibility to the audiences options() offers. */
    public static function rule(?Visibility $current = null): Enum
    {
        return VisibilityChoices::rule(self::options($current));
    }

    /** Validation rule accepting exactly what optionsFor($member) offered them. */
    public static function ruleFor(Member $member): Enum
    {
        return VisibilityChoices::rule(self::optionsFor($member));
    }

    /** Whether the SNS lets members make their age visible to web guests. */
    public static function allowsWebPublic(): bool
    {
        return (bool) app(SnsSettingService::class)->get(SnsSettingKey::AllowWebPublicAge);
    }
}
