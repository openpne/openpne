import { usePage } from '@inertiajs/react';
import {
    type DateFormatContext,
    formatAbsolute,
    formatCivilDate,
    formatCivilMonth,
    formatCivilMonthShort,
    formatClockTime,
    formatDayLabel,
    formatExact,
    formatListStamp,
    relativeDeadline,
    relativeParts,
    siteCurrentYear,
    siteDay,
    siteDayOffset,
} from '@/lib/date';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

/**
 * Binds the date formatters to the site's locale and timezone from the shared props. A hook rather
 * than module state because the locale can change without a full page load (see SyncLocaleWithServer),
 * and a stale locale here would render dates in one language beside a UI in another.
 *
 * Deliberately not memoized: the relative label needs the translator, which is a fresh closure every
 * render, so a memo keyed on it would never hit and a memo ignoring it would render the old language.
 * These are a handful of closures.
 */
export function useDateFormat(): {
    absolute: (iso: string) => string;
    listStamp: (iso: string) => string;
    clockTime: (iso: string) => string;
    relative: (iso: string, now?: Date) => string;
    relativeDeadline: (iso: string, now?: Date) => number | null;
    /** The site's calendar day for an instant, as `Y-m-d`; null when the value is not a date. */
    siteDay: (iso: string) => string | null;
    /** What a conversation's date heading says over the rows of that day. */
    dayHeading: (iso: string, now?: Date) => string;
    civilDate: (value: string, weekday?: boolean) => string;
    civilMonth: (year: number, month: number) => string;
    civilMonthShort: (month: number) => string;
    exact: (iso: string) => string;
    currentYear: () => number;
} {
    const { locale, timezone } = usePage<PageProps>().props;
    const t = useT();
    const context: DateFormatContext = { locale, timeZone: timezone };

    return {
        absolute: (iso) => formatAbsolute(iso, context),
        listStamp: (iso) => formatListStamp(iso, context),
        clockTime: (iso) => formatClockTime(iso, context),
        relative: (iso, now) => relativeLabel(iso, context, t, now),
        relativeDeadline: (iso, now) => relativeDeadline(iso, context.timeZone, now),
        siteDay: (iso) => siteDay(iso, context.timeZone),
        dayHeading: (iso, now) => dayHeadingLabel(iso, context, t, now),
        civilDate: (value, weekday) => formatCivilDate(value, context, weekday),
        civilMonth: (year, month) => formatCivilMonth(year, month, context),
        civilMonthShort: (month) => formatCivilMonthShort(month, context),
        exact: (iso) => formatExact(iso, context),
        currentYear: () => siteCurrentYear(context),
    };
}

/**
 * The wording for "how long ago", falling back to a list stamp once that stops being the useful answer.
 *
 * Singular and plural are separate keys rather than one `:count` key: English needs "a minute ago", not
 * "1 minutes ago", and the wrapper exposes only the string form of `t` (see i18n.ts). Every key is a
 * literal here so the translation scanner can find it.
 */
/**
 * The wording for a conversation's date heading. Only today and yesterday become words: past that the
 * reader is placing the day on a calendar rather than against now, and {@link formatDayLabel} writes it
 * out with the weekday they place it by.
 */
function dayHeadingLabel(iso: string, context: DateFormatContext, t: (key: string) => string, now?: Date): string {
    switch (siteDayOffset(iso, context.timeZone, now)) {
        case 0:
            return t('Today');
        case 1:
            return t('Yesterday');
        default:
            return formatDayLabel(iso, context, now);
    }
}

function relativeLabel(
    iso: string,
    context: DateFormatContext,
    t: (key: string, replacements?: Record<string, number>) => string,
    now?: Date,
): string {
    const parts = relativeParts(iso, context.timeZone, now);

    if (parts === null) {
        return formatListStamp(iso, context);
    }

    switch (parts.unit) {
        case 'now':
            return t('Just now');
        case 'minute':
            return parts.count === 1 ? t('A minute ago') : t(':count minutes ago', { count: parts.count });
        case 'hour':
            return parts.count === 1 ? t('An hour ago') : t(':count hours ago', { count: parts.count });
        case 'day':
            return parts.count === 1 ? t('A day ago') : t(':count days ago', { count: parts.count });
    }
}
