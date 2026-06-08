<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\App;

/**
 * Ports OpenPNE 3 op_format_date's culture-aware presets. Japanese renders the kanji pattern
 * op_format_date hard-codes for ja_JP; other locales use Carbon's localized format. OpenPNE 3's
 * XDateTimeJaBr stacks the date in a narrow sidebar column — the Classic layout shows it inline,
 * so the single-line XDateTimeJa form is the faithful equivalent.
 */
final class LocalizedDate
{
    /** op_format_date XDateTimeJa: "2026年06月04日 13:44" for ja, a localized full datetime otherwise. */
    public static function dateTime(CarbonInterface $date): string
    {
        if (App::getLocale() === 'ja') {
            return $date->format('Y年m月d日 H:i');
        }

        return $date->locale(App::getLocale())->isoFormat('LLL');
    }

    /** op_format_date XDateJa ('D' preset): "2026年06月04日" for ja, a localized date otherwise. */
    public static function date(CarbonInterface $date): string
    {
        if (App::getLocale() === 'ja') {
            return $date->format('Y年m月d日');
        }

        return $date->locale(App::getLocale())->isoFormat('LL');
    }
}
