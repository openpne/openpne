<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\App;

/**
 * Ports OpenPNE 3 op_format_date's culture-aware presets. Japanese renders the kanji pattern
 * op_format_date hard-codes for ja_JP; other locales use Carbon's localized format.
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

    /**
     * op_format_date XDateTimeJaBr, split into lines instead of carrying the `\n` its callers
     * pass through nl2br: ["2026年", "06月04日", "13:44"] for ja, so a narrow dt column stacks the
     * parts. Other cultures fall back to the same one-line datetime XDateTimeJa gives them.
     *
     * @return list<string>
     */
    public static function dateTimeLines(CarbonInterface $date): array
    {
        if (App::getLocale() === 'ja') {
            return [$date->format('Y年'), $date->format('m月d日'), $date->format('H:i')];
        }

        return [self::dateTime($date)];
    }

    /** op_format_date XDateJa ('D' preset): "2026年06月04日" for ja, a localized date otherwise. */
    public static function date(CarbonInterface $date): string
    {
        if (App::getLocale() === 'ja') {
            return $date->format('Y年m月d日');
        }

        return $date->locale(App::getLocale())->isoFormat('LL');
    }

    /**
     * op_format_date XShortDateJa: "06月04日" for ja (year stripped), a localized month+day
     * otherwise. The locale is passed in (not read from App) so a profile rendered for an
     * explicit lang formats consistently regardless of the request locale.
     */
    public static function monthDay(CarbonInterface $date, string $locale): string
    {
        if ($locale === 'ja') {
            return $date->format('m月d日');
        }

        return $date->locale($locale)->isoFormat('MMMM D');
    }
}
