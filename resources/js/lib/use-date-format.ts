import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import {
    type DateFormatContext,
    formatAbsolute,
    formatCivilDate,
    formatCivilMonth,
    formatCivilMonthShort,
    formatExact,
    formatListStamp,
    siteCurrentYear,
} from '@/lib/date';
import type { PageProps } from '@/types';

/**
 * Binds the date formatters to the site's locale and timezone from the shared props. A hook rather
 * than module state because the locale can change without a full page load (see SyncLocaleWithServer),
 * and a stale locale here would render dates in one language beside a UI in another.
 */
export function useDateFormat(): {
    absolute: (iso: string) => string;
    listStamp: (iso: string) => string;
    civilDate: (value: string, weekday?: boolean) => string;
    civilMonth: (year: number, month: number) => string;
    civilMonthShort: (month: number) => string;
    exact: (iso: string) => string;
    currentYear: () => number;
} {
    const { locale, timezone } = usePage<PageProps>().props;

    return useMemo(() => {
        const context: DateFormatContext = { locale, timeZone: timezone };

        return {
            absolute: (iso) => formatAbsolute(iso, context),
            listStamp: (iso) => formatListStamp(iso, context),
            civilDate: (value, weekday) => formatCivilDate(value, context, weekday),
            civilMonth: (year, month) => formatCivilMonth(year, month, context),
            civilMonthShort: (month) => formatCivilMonthShort(month, context),
            exact: (iso) => formatExact(iso, context),
            currentYear: () => siteCurrentYear(context),
        };
    }, [locale, timezone]);
}
