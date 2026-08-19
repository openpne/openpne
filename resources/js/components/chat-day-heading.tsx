import { usePage } from '@inertiajs/react';
import { useDateFormat } from '@/lib/use-date-format';
import { useSiteDay } from '@/lib/use-site-day';
import type { PageProps } from '@/types';

/**
 * The day a run of messages was said on, drawn above the first of them. It is what lets the rows below
 * carry only a time (docs/internals/datetime.md): the day is said once for the whole stretch rather
 * than on every line.
 *
 * It sticks, because a day of any length outlives its own heading on screen — and then the rows below
 * say only `09:12`, with nothing left to say which day that was. Sticking is what keeps "said once"
 * from meaning "said once and then gone": the same element goes on answering instead of a second one
 * being added to the rows. Its containing block is its own day's group (lib/chat/day-groups), which is
 * what makes the next day push it out rather than the two piling up at the same offset.
 *
 * The unread separator does **not** stick, and the difference is not decoration. That line is a claim
 * about a position, to the point that it is withdrawn entirely when the position cannot be drawn
 * honestly (lib/chat/unread: a line against the top edge would mark where pagination stopped rather
 * than where reading did). Pinning it would leave it claiming "the unread starts here" over rows the
 * reader has scrolled well past — the one thing its own contract refuses to do.
 *
 * A pill rather than the ruled band that separator draws, because the two meet: the first unread
 * message of a morning is also the first message of that day. Two ruled bands stacked read as one
 * confused divider.
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
        // Opaque, and the width of the list rather than the width of its label: a row here runs the
        // full column, so a pill floating over the middle of one lands on the author's name — measured
        // at 390px, where the label sat inside it. The rows pass under this band instead.
        //
        // Under the banner's z-20, so a jump-to-unread offer standing at the top of the conversation
        // covers the heading rather than being covered by it.
        <div className="sticky top-[calc(var(--modern-top-offset)+0.5rem)] z-10 flex justify-center bg-card px-4 py-2 sm:px-5">
            <div role="separator" aria-label={label}>
                {/* `title` because the visible text abbreviates — `今日` on its own does not say which
                    day it was, and every other shape that leaves something out offers the whole value
                    the same way. Non-essential by the same rule: `dateTime` is what carries it. */}
                <time
                    aria-hidden
                    dateTime={day}
                    title={date.dayHeadingTitle(at)}
                    className="rounded-full bg-muted px-3 py-0.5 text-xs text-muted-foreground shadow-sm"
                >
                    {label}
                </time>
            </div>
        </div>
    );
}
