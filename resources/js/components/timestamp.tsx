import { useDateFormat } from '@/lib/use-date-format';

/**
 * How much of an instant the display shows. `instant` names the time of day; `day` names only the
 * calendar day, so it stands in for something more precise and carries the exact value as a title.
 */
export type TimestampPreset = 'instant' | 'day';

/**
 * The single element every displayed instant goes through. It exists to make three things structural
 * rather than per-call-site: the machine-readable value lives in `dateTime`, the site's clock and
 * locale come from the shared props, and a display that drops information offers the exact value.
 *
 * A title is deliberately absent when the display already names the second — repeating it there is
 * noise, and `title` is reachable by neither screen readers nor the keyboard, so it must never be the
 * only place a value appears.
 */
export function Timestamp({ at, preset = 'instant', className }: { at: string; preset?: TimestampPreset; className?: string }) {
    const date = useDateFormat();

    return (
        <time dateTime={at} title={preset === 'day' ? date.exact(at) : undefined} className={className}>
            {preset === 'day' ? date.instantDate(at) : date.instant(at)}
        </time>
    );
}

/**
 * A calendar day with no instant behind it — an event's open date, not when a row was written. Separate
 * from {@link Timestamp} so the two cannot be swapped: rendering a civil date as an instant shifts it a
 * day for viewers west of the site (resources/js/lib/date.ts). No title, because `Y-m-d` is already the
 * whole value.
 */
export function CivilDate({ value, className }: { value: string; className?: string }) {
    const date = useDateFormat();

    return (
        <time dateTime={value} className={className}>
            {date.civilDate(value)}
        </time>
    );
}
