import { usePage } from '@inertiajs/react';
import { useDateFormat } from '@/lib/use-date-format';
import { useRelativeRefresh } from '@/lib/use-relative-refresh';
import { useSiteDay } from '@/lib/use-site-day';
import type { PageProps } from '@/types';

/** Which shape a displayed instant takes (docs/internals/datetime.md, "Shapes"). */
export type TimestampPreset = 'absolute' | 'listStamp' | 'relative' | 'clockTime';

/**
 * The hover title is a mouse-only convenience: `title` on a non-interactive element reaches neither
 * the keyboard nor assistive technology, so anything a reader needs in order to act belongs in the
 * visible text or in `dateTime`.
 */
export function Timestamp({ at, preset, className }: { at: string; preset: TimestampPreset; className?: string }) {
    const date = useDateFormat();
    // One reading of the clock for both the text and the deadline: two can straddle a boundary,
    // leaving the timer waiting for the one the text has already passed.
    const now = new Date();

    // Subscribed for every preset because a hook cannot be conditional: the day-relative shapes have
    // to be re-read when the site's day turns over, and an absolute one re-renders to the same string.
    useSiteDay(usePage<PageProps>().props.timezone);
    // Only a relative stamp has a boundary of its own to wait for; null keeps a page of list stamps
    // from holding a timer each.
    useRelativeRefresh(preset === 'relative' ? date.relativeDeadline(at, now) : null);

    // A lookup rather than a chain of ternaries: a record over the union has to name every preset or
    // it does not compile, where a catch-all chain let a new one fall through to `absolute`.
    const text = {
        absolute: () => date.absolute(at),
        listStamp: () => date.listStamp(at),
        relative: () => date.relative(at, now),
        clockTime: () => date.clockTime(at),
    }[preset]();

    return (
        <time dateTime={at} title={preset === 'absolute' ? undefined : date.exact(at)} className={className}>
            {text}
        </time>
    );
}

/**
 * Separate from {@link Timestamp} so the two cannot be swapped: rendering a civil date as an instant
 * shifts it a day for viewers west of the site (docs/internals/datetime.md, "Shapes").
 */
export function CivilDate({ value, weekday = false, className }: { value: string; weekday?: boolean; className?: string }) {
    const date = useDateFormat();

    return (
        <time dateTime={value} className={className}>
            {date.civilDate(value, weekday)}
        </time>
    );
}
