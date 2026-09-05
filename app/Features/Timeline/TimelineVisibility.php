<?php

namespace App\Features\Timeline;

use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use App\Support\VisibilityChoices;
use Illuminate\Validation\Rules\Enum;

/**
 * Single source for the form options and the request validation rule so the two cannot drift; both
 * honour SnsSettingKey::TimelineAllowWebPublic and the friend unit. A post is never edited, so no
 * form here re-posts a stored audience and the sticky current its siblings take has no call site.
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
