<?php

namespace App\Features\Profile;

/**
 * OpenPNE 3 `is_allow_config_public_flag_profile_page`: 0 let members choose, 1 held every profile
 * page at members-only, 4 opened every one to the web (docs/internals/member-profile.md, "Profile page audience").
 */
enum ProfileVisibilityPolicy: string
{
    case MemberChoice = 'member_choice';

    case Members = 'members';

    case Web = 'web';

    public function label(): string
    {
        return match ($this) {
            self::MemberChoice => __('Let each member choose'),
            self::Members => __('All members, no member choice'),
            self::Web => __('Anyone on the web, no member choice'),
        };
    }
}
