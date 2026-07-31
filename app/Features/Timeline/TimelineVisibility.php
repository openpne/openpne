<?php

namespace App\Features\Timeline;

use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use App\Support\VisibilityChoices;
use Illuminate\Validation\Rules\Enum;

/**
 * The audiences a member may choose when posting. Single source for the form options and the
 * request validation rule so the two cannot drift: both honour the SnsSettingKey::TimelineAllowWebPublic
 * setting (OpenPNE 3 op_activity_is_open) and the friend unit's state (VisibilityChoices). The form
 * default is Members (OpenPNE 3 public_flag SNS).
 *
 * A post is never edited (a reply copies its root's audience verbatim), so no form here re-posts a
 * stored audience and the sticky current its siblings take has no call site.
 */
final class TimelineVisibility
{
    /** @return list<Visibility> */
    public static function options(): array
    {
        return VisibilityChoices::offered(self::allowsWebPublic());
    }

    /** Validation rule restricting visibility to the audiences options() offers. */
    public static function rule(): Enum
    {
        return VisibilityChoices::rule(self::options());
    }

    /**
     * Whether the SNS serves web-public (Open) posts at all. Read by the read path too
     * (TimelineAccess / TimelineVisibilityScope): turning the setting off must hide posts already
     * stored as Open from guests, not merely stop new ones being written.
     */
    public static function allowsWebPublic(): bool
    {
        return (bool) app(SnsSettingService::class)->get(SnsSettingKey::TimelineAllowWebPublic);
    }
}
