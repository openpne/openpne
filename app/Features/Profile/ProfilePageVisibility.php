<?php

namespace App\Features\Profile;

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
        $policy = app(SnsSettingService::class)->get(SnsSettingKey::ProfileVisibilityPolicy);

        return $policy instanceof ProfileVisibilityPolicy ? $policy : ProfileVisibilityPolicy::Members;
    }

    public static function memberMayChoose(): bool
    {
        return self::policy() === ProfileVisibilityPolicy::MemberChoice;
    }

    /** @return list<Visibility> OpenPNE 3's two choices; the profile page has no friends or private tier. */
    public static function options(): array
    {
        return [Visibility::Members, Visibility::Open];
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
