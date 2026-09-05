// An instant (offset-bearing ISO) and a civil date (`Y-m-d`) are different types here: reading a
// civil date as an instant shifts it a day west of UTC (docs/internals/datetime.md, "Key
// invariants").

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

export function formatAbsolute(iso: string, { locale, timeZone }: DateFormatContext): string {
    return render(new Date(iso), iso, locale, { ...YEAR_MONTH_DAY, ...HOUR_MINUTE, timeZone });
}

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

    return render(date, iso, locale, { ...dayShape(then, today), timeZone });
}

/**
 * A function rather than the same comparison written out in each formatter, so the rule cannot be
 * changed in one and left behind in the other (docs/internals/datetime.md, "Rules").
 */
function dayShape(then: { year: number }, today: { year: number }): Intl.DateTimeFormatOptions {
    return then.year === today.year ? MONTH_DAY : YEAR_MONTH_DAY;
}

/**
 * Carries the weekday, which the shapes above do not (docs/internals/datetime.md, "A conversation").
 */
export function formatDayLabel(iso: string, context: DateFormatContext, now: Date = new Date()): string {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return iso;
    }

    const { locale, timeZone } = context;

    return render(date, iso, locale, {
        ...dayShape(siteDayParts(date, timeZone), siteDayParts(now, timeZone)),
        weekday: 'short',
        timeZone,
    });
}

/**
 * Separate from {@link formatListStamp}, whose today branch renders the same string but whose shape
 * depends on the clock (docs/internals/datetime.md, "A conversation").
 */
export function formatClockTime(iso: string, { locale, timeZone }: DateFormatContext): string {
    return render(new Date(iso), iso, locale, { ...HOUR_MINUTE, timeZone });
}

/** A calendar day with no instant attached, as the day it was stored — in every browser timezone. */
export function formatCivilDate(value: string, { locale }: DateFormatContext, weekday = false): string {
    return render(civilToUtcInstant(value), value, locale, {
        ...YEAR_MONTH_DAY,
        ...(weekday ? { weekday: 'short' as const } : {}),
        timeZone: UTC,
    });
}

export function formatExact(iso: string, { locale, timeZone }: DateFormatContext): string {
    return render(new Date(iso), iso, locale, {
        ...YEAR_MONTH_DAY,
        ...HOUR_MINUTE,
        second: '2-digit',
        timeZone,
    });
}

export function formatCivilMonth(year: number, month: number, { locale }: DateFormatContext): string {
    return new Intl.DateTimeFormat(locale, { year: 'numeric', month: 'long', timeZone: UTC }).format(
        Date.UTC(year, month - 1, 1),
    );
}

export function formatCivilMonthShort(month: number, { locale }: DateFormatContext): string {
    return new Intl.DateTimeFormat(locale, { month: 'short', timeZone: UTC }).format(Date.UTC(2000, month - 1, 1));
}

/** Longer than any civil day: a fall-back day is 25 hours, and this only has to bracket the boundary. */
const LONGEST_SITE_DAY_MS = 26 * 3_600_000;

const RELATIVE_DAY_LIMIT = 7;

/**
 * Null once "how long ago" stops being the useful answer and a date should be shown instead; days are
 * calendar days on the site's clock (docs/internals/datetime.md, "How long ago").
 */
export function relativeParts(
    iso: string,
    timeZone: string,
    now: Date = new Date(),
): { unit: 'now' } | { unit: 'minute' | 'hour' | 'day'; count: number } | null {
    const bucket = relativeBucket(iso, timeZone, now);

    if (bucket === null || bucket.unit === 'beyond') {
        return null;
    }

    return bucket.unit === 'now' ? { unit: 'now' } : { unit: bucket.unit, count: bucket.count };
}

/**
 * The instant this stamp's text stops being true, or null when the next change is a site-day boundary
 * the shared clock already waits on (docs/internals/datetime.md, "Key invariants").
 */
export function relativeDeadline(iso: string, timeZone: string, now: Date = new Date()): number | null {
    const bucket = relativeBucket(iso, timeZone, now);

    if (bucket === null || bucket.unit === 'beyond') {
        return null;
    }

    const difference = now.getTime() - new Date(iso).getTime();

    // Signed, so a clock running ahead of ours schedules one wake at the moment its text really changes
    // rather than one a minute until it catches up.
    if (bucket.unit === 'now') {
        return now.getTime() + (60_000 - difference);
    }

    if (bucket.unit === 'minute') {
        return now.getTime() + (60_000 - (difference % 60_000));
    }

    // A day count only changes when the calendar does, which the shared day clock already waits on.
    if (bucket.unit === 'day') {
        return null;
    }

    // The hour bucket changes on the hour, and its exit into days is a site-day boundary the day
    // clock has.
    return now.getTime() + (3_600_000 - (difference % 3_600_000));
}

/**
 * The one place the buckets are decided, so the text and its deadline cannot disagree about which one
 * a stamp is in. `days === 0` keeps the hour bucket even past 24 hours elapsed, because a fall-back
 * day is 25 hours long (docs/internals/datetime.md, "How long ago").
 */
function relativeBucket(
    iso: string,
    timeZone: string,
    now: Date,
): { unit: 'now' } | { unit: 'minute' | 'hour' | 'day'; count: number } | { unit: 'beyond' } | null {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return null;
    }

    // A future instant means a clock a little ahead of ours, not a prediction; "just now" is the
    // truthful reading, and negative counts are not.
    const elapsed = Math.max(0, now.getTime() - date.getTime());

    if (elapsed < 60_000) {
        return { unit: 'now' };
    }

    if (elapsed < 3_600_000) {
        return { unit: 'minute', count: Math.floor(elapsed / 60_000) };
    }

    const days = siteDayNumber(now, timeZone) - siteDayNumber(date, timeZone);

    if (elapsed < 86_400_000 || days === 0) {
        return { unit: 'hour', count: Math.floor(elapsed / 3_600_000) };
    }

    return days < RELATIVE_DAY_LIMIT ? { unit: 'day', count: days } : { unit: 'beyond' };
}

/** The site's calendar day as a day count, so two of them subtract into a number of days. */
function siteDayNumber(date: Date, timeZone: string): number {
    const { year, month, day } = siteDayParts(date, timeZone);

    return Date.UTC(year, month - 1, day) / 86_400_000;
}

/**
 * Searched for rather than calculated, because neither shortcut survives a DST transition
 * (docs/internals/datetime.md, "Key invariants").
 */
export function msUntilNextSiteDay(now: Date, timeZone: string): number {
    const today = siteDayKey(now, timeZone);
    let sameDay = now.getTime();
    let nextDay = sameDay + LONGEST_SITE_DAY_MS;

    if (siteDayKey(new Date(nextDay), timeZone) === today) {
        return LONGEST_SITE_DAY_MS; // Unreachable for real zones; re-arming re-checks rather than trusting it.
    }

    // To the millisecond: a wake a second late is the direction that shows a stale stamp.
    while (nextDay - sameDay > 1) {
        const midpoint = sameDay + Math.floor((nextDay - sameDay) / 2);

        if (siteDayKey(new Date(midpoint), timeZone) === today) {
            sameDay = midpoint;
        } else {
            nextDay = midpoint;
        }
    }

    return Math.max(0, nextDay - now.getTime());
}

function siteDayKey(date: Date, timeZone: string): string {
    const { year, month, day } = siteDayParts(date, timeZone);

    return `${year}-${month}-${day}`;
}

/**
 * Two rows carry the same value when a conversation should draw one date heading over both. Zero-
 * padded, unlike {@link siteDayKey}, because this one is displayed and `2026-8-9` is not a valid
 * date string.
 */
export function siteDay(iso: string, timeZone: string): string | null {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return null;
    }

    const { year, month, day } = siteDayParts(date, timeZone);

    return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

/**
 * How many of the site's calendar days back an instant is: 0 today, 1 yesterday. Negative for a clock
 * running ahead of ours, which the caller reads as "not today and not yesterday" and dates normally.
 */
export function siteDayOffset(iso: string, timeZone: string, now: Date = new Date()): number | null {
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return null;
    }

    return siteDayNumber(now, timeZone) - siteDayNumber(date, timeZone);
}

/**
 * `new Date().getFullYear()` is the viewer's year, which is the previous one for a browser west of a
 * site that has just crossed into January.
 */
export function siteCurrentYear({ timeZone }: DateFormatContext, now: Date = new Date()): number {
    return siteDayParts(now, timeZone).year;
}

/**
 * Pinned to en-US so the parts are Gregorian digits: this feeds comparisons, and a locale with its
 * own calendar or numerals would make "same day" depend on the display language.
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
 * Pinned to UTC midnight and read back in UTC, so the day survives the round trip whatever the
 * browser's zone is. The round-trip comparison catches a date that parses but does not exist —
 * `Date.UTC` rolls `2026-02-31` into March — and the two-digit-year window, where `Date.UTC(26, …)`
 * means 1926.
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
