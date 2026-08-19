import { useDateFormat } from '@/lib/use-date-format';
import { useSiteDay } from '@/lib/use-site-day';
import { usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';

/**
 * The day a run of messages was said on, drawn above the first of them. It is what lets the rows
 * below carry only a time (docs/internals/datetime.md): the day is said once for the whole stretch
 * rather than on every line.
 *
 * A pill rather than the ruled band the unread separator draws, because the two meet: the first
 * unread message of a morning is also the first message of that day, so both are drawn at the same
 * boundary most days. Two ruled bands stacked read as one confused divider, and telling them apart by
 * suppressing one of the rules conditionally would make the shape depend on where it lands. They are
 * different shapes instead — and the heading is the outer one, since the day is true for every reader
 * while the unread line is only this reader's place.
 *
 * An `<li>` carrying a `role="separator"` child rather than being one: a list may only hold list
 * items, and an `<li role="separator">` is the one thing axe's `list` rule refuses. The label says the
 * whole of what it means, so what it draws is hidden.
 *
 * The third element that emits a civil date (`Timestamp` and `CivilDate` are the others), because the
 * visible text is `今日` where the machine value has to stay a day — see datetime.md's first invariant.
 */
export function ChatDayHeading({ at }: { at: string }) {
    const date = useDateFormat();
    // The wording is relative to today, so it has to be re-read when the site's day turns over — the
    // same shared clock every other clock-dependent shape waits on.
    useSiteDay(usePage<PageProps>().props.timezone);

    const day = date.siteDay(at);
    if (day === null) {
        return null;
    }

    const label = date.dayHeading(at);

    return (
        <li className="px-4 py-2 sm:px-5">
            <div role="separator" aria-label={label} className="flex justify-center">
                <time aria-hidden dateTime={day} className="rounded-full bg-muted px-3 py-0.5 text-xs text-muted-foreground">
                    {label}
                </time>
            </div>
        </li>
    );
}
