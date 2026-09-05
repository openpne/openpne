<?php

namespace App\Features\Diary;

use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use Carbon\CarbonImmutable;

/** The site-wide search screen's two switches; neither reaches a member archive's keyword filter (docs/internals/diary.md, "Search"). */
final class DiarySearch
{
    public static function enabled(): bool
    {
        return (bool) app(SnsSettingService::class)->get(SnsSettingKey::DiarySearchEnabled);
    }

    /** OpenPNE 3 `date('Y-m-d 00:00:00', strtotime('-N days'))`: midnight N days ago in the site zone, so 0 is today alone. */
    public static function periodLowerBound(): ?CarbonImmutable
    {
        $settings = app(SnsSettingService::class);

        if (! $settings->get(SnsSettingKey::DiarySearchPeriodEnabled)) {
            return null;
        }

        return CarbonImmutable::now()->subDays((int) $settings->get(SnsSettingKey::DiarySearchPeriodDays))->startOfDay();
    }
}
