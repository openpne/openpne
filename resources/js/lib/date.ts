/**
 * Format a date-only value (`Y-m-d`, no time or zone) for display without a timezone shift.
 * `new Date('2026-07-09')` parses as UTC midnight, which `toLocaleDateString()` can render as the
 * previous day for a browser west of UTC. Building the Date from local parts keeps the calendar day.
 */
export function formatDateOnly(ymd: string): string {
    const [year = 0, month = 1, day = 1] = ymd.split('-').map(Number);
    return new Date(year, month - 1, day).toLocaleDateString();
}
