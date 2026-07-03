const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/;

/**
 * Format the calendar-day part for display. Accepts an ISO datetime or a date-only `Y-m-d` string;
 * the latter is built from local parts because `new Date('2026-07-09')` parses as UTC midnight,
 * which `toLocaleDateString()` can render as the previous day for a browser west of UTC.
 */
export function formatDate(value: string): string {
    return parse(value).toLocaleDateString();
}

/** Format an ISO datetime with its time part. Not for date-only values — use {@link formatDate}. */
export function formatDateTime(iso: string): string {
    return new Date(iso).toLocaleString();
}

function parse(value: string): Date {
    if (DATE_ONLY.test(value)) {
        const [year = 0, month = 1, day = 1] = value.split('-').map(Number);
        return new Date(year, month - 1, day);
    }
    return new Date(value);
}
