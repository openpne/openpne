// Display formatting for the two kinds of date value the payload carries, which must not be
// conflated: an *instant* (offset-bearing ISO, e.g. `created_at`) and a *civil date* (`Y-m-d` with no
// time, e.g. an event's open date). An instant renders in the site timezone; a civil date has no
// timezone to render in, and reading one as an instant is what shifts it a day west of UTC.
//
// Locale and timezone are passed in rather than left to the browser: the site's locale is an
// admin/member choice the browser knows nothing about, and the site timezone is the one clock Classic
// also renders in (docs/internals/runtime.md). React callers bind them via useDateFormat.
//
// Which shape goes where is docs/internals/datetime.md. Two rules are baked in here rather than left
// to call sites: seconds never reach a display, and the hour is always two digits so a column of
// times lines up.

const UTC = 'UTC';
const CIVIL_DATE = /^(\d{4})-(\d{2})-(\d{2})$/;
const YEAR_MONTH_DAY = { year: 'numeric', month: 'long', day: 'numeric' } as const;
const MONTH_DAY = { month: 'long', day: 'numeric' } as const;
const HOUR_MINUTE = { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' } as const;

export interface DateFormatContext {
    locale: string;
    /** IANA name, from the `timezone` shared prop (config `app.timezone`). */
    timeZone: string;
}

/**
 * The whole date and time, for a page that is the permanent reference for one thing — a diary entry,
 * a message. The year is always present because such a page gets linked to and read out of its
 * original context.
 */
export function formatAbsolute(iso: string, { locale, timeZone }: DateFormatContext): string {
    return render(new Date(iso), iso, locale, { ...YEAR_MONTH_DAY, ...HOUR_MINUTE, timeZone });
}

/**
 * Only the part that distinguishes a row from the ones around it: today's rows differ by time, this
 * year's by date, older ones by year. Dropping what the reader can infer from where they are is what
 * keeps a list scannable — and it is why this shape, unlike {@link formatAbsolute}, is worth revealing
 * in full on hover.
 */
export function formatListStamp(iso: string, context: DateFormatContext, now: Date = new Date()): string {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return iso;
    }

    const { locale, timeZone } = context;
    const then = siteDayParts(date, timeZone);
    const today = siteDayParts(now, timeZone);

    if (then.year === today.year && then.month === today.month && then.day === today.day) {
        return render(date, iso, locale, { ...HOUR_MINUTE, timeZone });
    }

    return render(date, iso, locale, { ...(then.year === today.year ? MONTH_DAY : YEAR_MONTH_DAY), timeZone });
}

/**
 * A calendar day with no instant attached, as the day it was stored — in every browser timezone. The
 * weekday is for a date someone plans around: an event's open date is read as "which day is that",
 * which the numbers alone do not answer.
 */
export function formatCivilDate(value: string, { locale }: DateFormatContext, weekday = false): string {
    return render(civilToUtcInstant(value), value, locale, {
        ...YEAR_MONTH_DAY,
        ...(weekday ? { weekday: 'short' as const } : {}),
        timeZone: UTC,
    });
}

/** What a list stamp is standing in for, down to the second, for the hover affordance. */
export function formatExact(iso: string, { locale, timeZone }: DateFormatContext): string {
    return render(new Date(iso), iso, locale, {
        ...YEAR_MONTH_DAY,
        ...HOUR_MINUTE,
        second: '2-digit',
        timeZone,
    });
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
 * shift a year-ranged view off the site's calendar.
 */
export function siteCurrentYear({ timeZone }: DateFormatContext, now: Date = new Date()): number {
    return siteDayParts(now, timeZone).year;
}

/**
 * Which calendar day an instant falls on for the site, as numbers. Pinned to en-US so the parts are
 * Gregorian digits: this feeds comparisons, and a locale with its own calendar or numerals would make
 * "same day" depend on the display language.
 */
function siteDayParts(date: Date, timeZone: string): { year: number; month: number; day: number } {
    const parts = new Intl.DateTimeFormat('en-US', {
        year: 'numeric',
        month: 'numeric',
        day: 'numeric',
        timeZone,
    }).formatToParts(date);
    const read = (type: string) => Number(parts.find((part) => part.type === type)?.value);

    return { year: read('year'), month: read('month'), day: read('day') };
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
