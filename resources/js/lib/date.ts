// Display formatting for the two kinds of date value the payload carries, which must not be
// conflated: an *instant* (offset-bearing ISO, e.g. `created_at`) and a *civil date* (`Y-m-d` with no
// time, e.g. an event's open date). An instant renders in the site timezone; a civil date has no
// timezone to render in, and reading one as an instant is what shifts it a day west of UTC.
//
// Locale and timezone are passed in rather than left to the browser: the site's locale is an
// admin/member choice the browser knows nothing about, and the site timezone is the one clock Classic
// also renders in (docs/internals/runtime.md). React callers bind them via useDateFormat.

const UTC = 'UTC';
const CIVIL_DATE = /^(\d{4})-(\d{2})-(\d{2})$/;
const DAY_PARTS = { year: 'numeric', month: 'numeric', day: 'numeric' } as const;
const TIME_PARTS = { hour: 'numeric', minute: '2-digit', second: '2-digit' } as const;

export interface DateFormatContext {
    locale: string;
    /** IANA name, from the `timezone` shared prop (config `app.timezone`). */
    timeZone: string;
}

/** A calendar day with no instant attached, as the day it was stored — in every browser timezone. */
export function formatCivilDate(value: string, { locale }: DateFormatContext): string {
    return render(civilToUtcInstant(value), value, locale, { ...DAY_PARTS, timeZone: UTC });
}

/** An instant, reduced to the calendar day it falls on in the site timezone. */
export function formatInstantDate(iso: string, { locale, timeZone }: DateFormatContext): string {
    return render(new Date(iso), iso, locale, { ...DAY_PARTS, timeZone });
}

/** An instant with its time of day, in the site timezone. */
export function formatInstant(iso: string, { locale, timeZone }: DateFormatContext): string {
    return render(new Date(iso), iso, locale, { ...DAY_PARTS, ...TIME_PARTS, timeZone });
}

/**
 * The whole instant, down to the second, for the affordance that reveals what a shortened display is
 * standing in for. Long month name rather than the numeric form so it cannot be misread as the display
 * value repeated.
 */
export function formatExact(iso: string, { locale, timeZone }: DateFormatContext): string {
    return render(new Date(iso), iso, locale, { dateStyle: 'long', timeStyle: 'medium', timeZone });
}

/** A civil year+month, for the diary archive headings. */
export function formatCivilMonth(year: number, month: number, { locale }: DateFormatContext): string {
    return new Intl.DateTimeFormat(locale, { year: 'numeric', month: 'long', timeZone: UTC }).format(
        Date.UTC(year, month - 1, 1),
    );
}

/** A civil month on its own, for the archive grid's column labels. */
export function formatCivilMonthShort(month: number, { locale }: DateFormatContext): string {
    return new Intl.DateTimeFormat(locale, { month: 'short', timeZone: UTC }).format(Date.UTC(2000, month - 1, 1));
}

/**
 * The year it currently is on the site's clock. `new Date().getFullYear()` is the viewer's year, which
 * is the previous one for a browser west of a site that has just crossed into January — enough to
 * shift a year-ranged view off the site's calendar. Pinned to the Gregorian calendar rather than the
 * display locale, since the result is arithmetic, not text.
 */
export function siteCurrentYear({ timeZone }: DateFormatContext, now: Date = new Date()): number {
    const parts = new Intl.DateTimeFormat('en-US', { year: 'numeric', timeZone }).formatToParts(now);

    return Number(parts.find((part) => part.type === 'year')?.value);
}

/**
 * `Intl` throws on an unrepresentable date where the old `toLocaleString` returned "Invalid Date", and
 * an absent timestamp does reach the client as `''` (the notification feed serializes a null
 * created_at that way). Falling back to the raw value keeps a bad payload a visible wrong string on
 * one row instead of an exception that takes the whole page down.
 */
function render(date: Date, raw: string, locale: string, options: Intl.DateTimeFormatOptions): string {
    if (Number.isNaN(date.getTime())) {
        return raw;
    }

    return new Intl.DateTimeFormat(locale, options).format(date);
}

/**
 * Pin `Y-m-d` to UTC midnight and read it back in UTC, so the day survives the round trip whatever
 * the browser's zone is. Anything that is not a bare `Y-m-d` — an instant passed to the civil-date
 * formatter by mistake, say — is left for {@link render} to surface verbatim rather than silently
 * shifted to a neighbouring day.
 *
 * The round-trip comparison is what makes that promise hold for a date that parses but does not
 * exist: `Date.UTC` rolls `2026-02-31` into March and `2026-13-01` into the next year, so matching the
 * regex is not enough. It also catches the two-digit-year window, where `Date.UTC(26, …)` would mean
 * 1926.
 */
function civilToUtcInstant(value: string): Date {
    const parts = CIVIL_DATE.exec(value);
    if (!parts) {
        return new Date(Number.NaN);
    }

    const [year, month, day] = [Number(parts[1]), Number(parts[2]), Number(parts[3])];
    const date = new Date(Date.UTC(year, month - 1, day));
    const survivedRoundTrip =
        date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day;

    return survivedRoundTrip ? date : new Date(Number.NaN);
}
