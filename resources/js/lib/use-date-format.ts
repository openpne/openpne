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
 * A hook rather than module state, because the locale can change without a full page load and a
 * stale one would render dates in one language beside a UI in another. Deliberately not memoized:
 * the relative label needs the translator, which is a fresh closure every render.
 */
export function useDateFormat(): {
    absolute: (iso: string) => string;
    listStamp: (iso: string) => string;
    clockTime: (iso: string) => string;
    relative: (iso: string, now?: Date) => string;
    relativeDeadline: (iso: string, now?: Date) => number | null;
    /** The site's calendar day for an instant, as `Y-m-d`; null when the value is not a date. */
    siteDay: (iso: string) => string | null;
    dayHeading: (iso: string, now?: Date) => string;
    dayHeadingTitle: (iso: string) => string;
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
        // Through the civil-date formatter rather than a shape of its own, year included: a title
        // exists to say what the visible text left out.
        dayHeadingTitle: (iso) => {
            const day = siteDay(iso, context.timeZone);

            return day === null ? '' : formatCivilDate(day, context, true);
        },
        civilDate: (value, weekday) => formatCivilDate(value, context, weekday),
        civilMonth: (year, month) => formatCivilMonth(year, month, context),
        civilMonthShort: (month) => formatCivilMonthShort(month, context),
        exact: (iso) => formatExact(iso, context),
        currentYear: () => siteCurrentYear(context),
    };
}

/**
 * Singular and plural are separate keys rather than one `:count` key, because English needs "a minute
 * ago" and only the string form of `t` is exposed. Every key is a literal here so the translation
 * scanner can find it.
 */
/**
 * Only today and yesterday become words: past that the reader is placing the day on a calendar rather
 * than against now, and {@link formatDayLabel} writes it out with the weekday.
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
