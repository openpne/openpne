<?php

namespace App\Features\Timeline;

use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;

/** See docs/internals/timeline.md, "Posting switch". */
final class TimelinePosting
{
    public static function enabled(): bool
    {
        return (bool) app(SnsSettingService::class)->get(SnsSettingKey::TimelinePostingEnabled);
    }
}
