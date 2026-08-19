import { useDateFormat } from '@/lib/use-date-format';
import { useSiteDay } from '@/lib/use-site-day';
import { usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';

/**
 * The day a run of messages was said on, drawn above the first of them. It is what lets the rows
 * below carry only a time (docs/internals/datetime.md): the day is said once for the whole stretch
 * rather than on every line.
 *
 * The same shape as the unread separator — a label between two rules — and a different colour. The
 * two meet most mornings, since the first unread message of a day is usually its first message, and
 * this was drawn as a pill for a while so that the meeting could not read as one confused divider.
 * That cost more than it bought: a label floating on its own does not say which side of it the day
 * belongs to, and readers asked. A rule answers that by being a boundary rather than a caption. What
 * keeps the two apart where they meet is that each carries its own label — colour reinforces it and
 * does not carry it, which greyscale shows and the load-older strip's identically coloured rule shows
 * again (docs/internals/group-talk.md). Mattermost does the same: one `Separator`, and
 * `NotificationSeparator` overriding only its colours.
 *
 * **The label sits on a pill and the unread line's does not, and that is not what separates them.**
 * The pill is here because components/chat-scroll-day.tsx reuses this shape — a floating copy has to
 * be legible over the words it covers, so it needs a background, and giving the same one to the
 * heading is what makes the two coincide when a heading scrolls up under it. A shape inherited from
 * elsewhere, not a device for telling a date from an unread line; that is still the labels.
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
        // Even room on both sides. A label between two rules is a separator whichever way you read it,
        // so it does not have to lean toward the day it names to say which one that is — which is what
        // the lopsided spacing it used to carry was for. A row under a separator adds no turn space of
        // its own (see the row), so this is the only thing setting either gap.
        <li className="px-4 py-4 sm:px-5">
            <div role="separator" aria-label={label} className="flex items-center gap-3">
                <span aria-hidden className="h-px flex-1 bg-border" />
                {/* `title` because the visible text abbreviates — `今日` on its own does not say which
                    day it was, and every other shape that leaves something out offers the whole value
                    the same way. Non-essential by the same rule: `dateTime` is what carries it. */}
                <time
                    aria-hidden
                    dateTime={day}
                    title={date.dayHeadingTitle(at)}
                    className="rounded-full bg-muted px-3 py-0.5 text-xs text-muted-foreground"
                >
                    {label}
                </time>
                <span aria-hidden className="h-px flex-1 bg-border" />
            </div>
        </li>
    );
}
