<?php

namespace App\Features\Profile;

use App\Features\Profile\Queries\BirthdayFieldExists;
use App\Models\Member;
use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use App\Support\VisibilityChoices;
use Illuminate\Validation\Rules\Enum;

/**
 * The member's own choice stays stored while the policy overrides it, as OpenPNE 3 kept
 * member_config through an admin override (docs/internals/member-profile.md, "Profile page audience").
 */
final class ProfilePageVisibility
{
    public static function policy(): ProfileVisibilityPolicy
    {
        return app(SnsSettingService::class)->get(SnsSettingKey::ProfileVisibilityPolicy);
    }

    public static function memberMayChoose(): bool
    {
        return self::policy() === ProfileVisibilityPolicy::MemberChoice;
    }

    /** @return list<Visibility> OpenPNE 3's two choices, web first as it listed them; the profile page has no friends or private tier. */
    public static function options(): array
    {
        return [Visibility::Open, Visibility::Members];
    }

    /** Whether the privacy category has anything to set: an age to gate, or the profile-page choice. */
    public static function privacyCategoryAvailable(): bool
    {
        return app(BirthdayFieldExists::class)() || self::memberMayChoose();
    }

    public static function rule(): Enum
    {
        return VisibilityChoices::rule(self::options());
    }

    /** A stored tier this page never offered (an earlier upgrade wrote Friends and Private) reads as Members. */
    public static function defaultFor(Member $member): Visibility
    {
        return $member->profile_visibility === Visibility::Open ? Visibility::Open : Visibility::Members;
    }

    public static function effective(Member $subject): Visibility
    {
        return match (self::policy()) {
            ProfileVisibilityPolicy::Web => Visibility::Open,
            ProfileVisibilityPolicy::Members => Visibility::Members,
            ProfileVisibilityPolicy::MemberChoice => self::defaultFor($subject),
        };
    }
}
