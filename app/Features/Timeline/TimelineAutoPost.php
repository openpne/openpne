<?php

namespace App\Features\Timeline;

use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;

/** The two OpenPNE 3 update_activity switches (docs/internals/timeline.md, "Automatic posts"). */
final class TimelineAutoPost
{
    public static function forDiaries(): bool
    {
        return (bool) app(SnsSettingService::class)->get(SnsSettingKey::DiaryAutoTimelinePost);
    }

    public static function forGroups(): bool
    {
        return (bool) app(SnsSettingService::class)->get(SnsSettingKey::GroupAutoTimelinePost);
    }
}
