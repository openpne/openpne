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
 * Call sites take the member-scoped pair (optionsFor / ruleFor): the form re-posts the audience the
 * member already has, and the unscoped pair would reject a tier no longer offered.
 */
final class AgeVisibility
{
    /** @return list<Visibility> */
    public static function options(?Visibility $current = null): array
    {
        return VisibilityChoices::offered(self::allowsWebPublic(), $current);
    }

    /** @return list<Visibility> */
    public static function optionsFor(Member $member): array
    {
        return self::options(self::defaultFor($member));
    }

    /** A stored Open pre-selects as Members while web-public age is off, since it then shows nobody the age. */
    public static function defaultFor(Member $member): Visibility
    {
        $preferred = $member->preference(PreferenceKey::AgeVisibility);

        return in_array($preferred, self::options($preferred), true) ? $preferred : Visibility::Members;
    }

    public static function rule(?Visibility $current = null): Enum
    {
        return VisibilityChoices::rule(self::options($current));
    }

    public static function ruleFor(Member $member): Enum
    {
        return VisibilityChoices::rule(self::optionsFor($member));
    }

    public static function allowsWebPublic(): bool
    {
        return (bool) app(SnsSettingService::class)->get(SnsSettingKey::AllowWebPublicAge);
    }
}
