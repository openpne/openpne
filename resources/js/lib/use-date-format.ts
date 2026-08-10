import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import {
    type DateFormatContext,
    formatCivilDate,
    formatCivilMonth,
    formatCivilMonthShort,
    formatInstant,
    formatInstantDate,
    siteCurrentYear,
} from '@/lib/date';
import type { PageProps } from '@/types';

/**
 * Binds the date formatters to the site's locale and timezone from the shared props. A hook rather
 * than module state because the locale can change without a full page load (see SyncLocaleWithServer),
 * and a stale locale here would render dates in one language beside a UI in another.
 */
export function useDateFormat(): {
    civilDate: (value: string) => string;
    civilMonth: (year: number, month: number) => string;
    civilMonthShort: (month: number) => string;
    instantDate: (iso: string) => string;
    instant: (iso: string) => string;
    currentYear: () => number;
} {
    const { locale, timezone } = usePage<PageProps>().props;

    return useMemo(() => {
        const context: DateFormatContext = { locale, timeZone: timezone };

        return {
            civilDate: (value) => formatCivilDate(value, context),
            civilMonth: (year, month) => formatCivilMonth(year, month, context),
            civilMonthShort: (month) => formatCivilMonthShort(month, context),
            instantDate: (iso) => formatInstantDate(iso, context),
            instant: (iso) => formatInstant(iso, context),
            currentYear: () => siteCurrentYear(context),
        };
    }, [locale, timezone]);
}
