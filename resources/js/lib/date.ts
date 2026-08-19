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
 * Only the time, for a row whose day is named by the heading above it — a message in a conversation.
 *
 * Separate from {@link formatListStamp}, whose today branch renders the same string: that one's shape
 * depends on the clock, so a conversation left open across the site's midnight would see every row
 * turn from `00:05` into `8月12日` (the shared day clock exists to do exactly that). A conversation's
 * rows are placed by their date heading and never change shape.
 */
export function formatClockTime(iso: string, { locale, timeZone }: DateFormatContext): string {
    return render(new Date(iso), iso, locale, { ...HOUR_MINUTE, timeZone });
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

/** Longer than any civil day: a fall-back day is 25 hours, and this only has to bracket the boundary. */
const LONGEST_SITE_DAY_MS = 26 * 3_600_000;

/** Past this the reader is no longer placing the row against now, so an absolute shape reads better. */
const RELATIVE_DAY_LIMIT = 7;

/**
 * How long ago, in the coarsest unit that still says something — or `null` once "how long ago" stops
 * being the useful answer and a date should be shown instead.
 *
 * Days are counted as **calendar days on the site's clock**, not as elapsed time divided by 24 hours.
 * Saturday 11:00 read on Monday 10:00 is 47 hours, which floor division calls one day ago; in Japanese
 * as in English, Saturday is two days before Monday. Hours below a day keep using elapsed time, so
 * yesterday 23:00 read at 01:00 is still "2 hours ago" rather than "1 day ago".
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
 * The instant this stamp's text stops being true, or `null` when the next change is a site-day boundary
 * — which the shared day clock already waits on, so scheduling it here as well would be a second timer
 * for the same moment.
 *
 * A deadline rather than a delay because the caller renders the text and arms the timer at two
 * different moments: a delay computed in one and applied in the other schedules the boundary *after*
 * the one the text is already past, leaving a stale bucket on screen until it fires.
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

    // A day count only changes when the calendar does, and the shared day clock is already waiting on
    // that. Arming here too would wake every hour to render the same words.
    if (bucket.unit === 'day') {
        return null;
    }

    // The hour bucket, which a long day can carry past 24 hours, changes on the hour. Its exit into days
    // is a site-day boundary, and the day clock has that one.
    return now.getTime() + (3_600_000 - (difference % 3_600_000));
}

/**
 * The one place the buckets are decided, so the text and its deadline can never disagree about which
 * one a stamp is in.
 *
 * `days === 0` keeps the hour bucket even past 24 hours elapsed. A fall-back day is 25 hours long, so
 * something posted at 00:30 is 24 hours old at 23:30 *the same day* — and "0 days ago" is not a reading
 * of anything. Hours below a day stay on elapsed time, which is what keeps yesterday 23:00 read at
 * 01:00 saying two hours rather than a day.
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
 * How long until the site's calendar day changes. `formatListStamp` renders relative to today, so a
 * page left open past the site's midnight would keep yesterday's rows saying a time — the shape
 * depends on the clock, and something has to re-read it (see use-site-day.ts).
 *
 * Searched for rather than calculated, because neither shortcut survives a DST transition. Counting
 * the remaining wall-clock seconds out of 86400 lands an hour late on a 23-hour day, and a delay that
 * is too long is the one error re-arming cannot recover. Converting the next local `00:00` to an
 * instant fails where that wall time does not exist at all — in America/Santiago on 2026-09-06 the
 * clock goes 23:59 → 01:00, so there is no midnight to convert and the conversion has no fixed point.
 *
 * What is always well defined is the first instant whose site date differs from now's, so that is what
 * this brackets and bisects. ~30 comparisons, once a day per page.
 */
export function msUntilNextSiteDay(now: Date, timeZone: string): number {
    const today = siteDayKey(now, timeZone);
    let sameDay = now.getTime();
    let nextDay = sameDay + LONGEST_SITE_DAY_MS;

    if (siteDayKey(new Date(nextDay), timeZone) === today) {
        return LONGEST_SITE_DAY_MS; // Unreachable for real zones; re-arming re-checks rather than trusting it.
    }

    // All the way to the millisecond. Stopping at second granularity would leave the wake up to a
    // second late, and late is the direction that shows a stale stamp.
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

/** The site's calendar day as a comparable string. */
function siteDayKey(date: Date, timeZone: string): string {
    const { year, month, day } = siteDayParts(date, timeZone);

    return `${year}-${month}-${day}`;
}

/**
 * Which calendar day an instant falls on for the site, as `Y-m-d`. Two rows carry the same value when
 * a conversation should draw one date heading over both, so this is the unit that decides where a
 * heading goes — computed on the site's clock, which is what keeps a heading from landing between two
 * different pairs of rows for two readers in different timezones.
 *
 * Zero-padded, unlike the internal {@link siteDayKey} above, because this one is displayed: it is the
 * `datetime` a date heading carries, and `2026-8-9` is not a valid date string.
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
