import { usePage } from '@inertiajs/react';
import { useDateFormat } from '@/lib/use-date-format';
import { useRelativeRefresh } from '@/lib/use-relative-refresh';
import { useSiteDay } from '@/lib/use-site-day';
import type { PageProps } from '@/types';

/**
 * Which shape a displayed instant takes. `absolute` is the whole date and time, for a page that is the
 * permanent reference for one thing. `listStamp` shows only what tells one row from its neighbours —
 * time for today, date for this year, year for anything older. `relative` says how long ago, for a
 * place whose whole point is what is new. Which screens get which is docs/internals/datetime.md.
 */
export type TimestampPreset = 'absolute' | 'listStamp' | 'relative';

/**
 * The single element every displayed instant goes through. It exists to make three things structural
 * rather than per-call-site: the machine-readable value lives in `dateTime`, the site's clock and
 * locale come from the shared props, and a shape that abbreviates offers the whole value on hover.
 *
 * That hover title is a mouse-only convenience and must stay non-essential: `title` on a
 * non-interactive element reaches neither the keyboard nor assistive technology, so anything a reader
 * needs in order to act belongs in the visible text or `dateTime`. `absolute` gets no title — it
 * already names the day and the minute, and a title differing only in its seconds is noise.
 */
export function Timestamp({ at, preset, className }: { at: string; preset: TimestampPreset; className?: string }) {
    const date = useDateFormat();
    // `listStamp` is shaped relative to today and `relative` counts days on the same calendar, so both
    // have to be re-read when the site's day turns over. Subscribed for every preset because a hook
    // cannot be conditional; an `absolute` stamp just re-renders once a day to the same string.
    useSiteDay(usePage<PageProps>().props.timezone);
    useRelativeRefresh(at);

    const text = preset === 'relative' ? date.relative(at) : preset === 'listStamp' ? date.listStamp(at) : date.absolute(at);

    return (
        <time dateTime={at} title={preset === 'absolute' ? undefined : date.exact(at)} className={className}>
            {text}
        </time>
    );
}

/**
 * A calendar day with no instant behind it — an event's open date, not when a row was written. Separate
 * from {@link Timestamp} so the two cannot be swapped: rendering a civil date as an instant shifts it a
 * day for viewers west of the site (resources/js/lib/date.ts). No title, because `Y-m-d` is already the
 * whole value.
 *
 * `weekday` is for a date someone plans around; it is on wherever an event's own dates are shown, so
 * the same datum never appears in two shapes.
 */
export function CivilDate({ value, weekday = false, className }: { value: string; weekday?: boolean; className?: string }) {
    const date = useDateFormat();

    return (
        <time dateTime={value} className={className}>
            {date.civilDate(value, weekday)}
        </time>
    );
}
