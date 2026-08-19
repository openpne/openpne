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
        // The label floats over the conversation rather than sitting in a band of its own. It will
        // overlap a row while it is pinned — there is nowhere in a full-width column that it would
        // not — so what has to be true is that it reads as being *above* that row. Measured at 390px:
        // over a card that is pure white, a `bg-muted` chip is 3.5 points of lightness darker and reads
        // as another word in the author's line. The fill is not what fixes it — `bg-secondary` is 4.5
        // points and still read that way with only a shadow. The border is what makes the edge, and it
        // is why the border and the shadow are both load-bearing here rather than decoration.
        //
        // Under the banner's z-20, so a jump-to-unread offer standing at the top of the conversation
        // covers the heading rather than being covered by it.
        <div className="pointer-events-none sticky top-[calc(var(--modern-top-offset)+0.5rem)] z-10 flex justify-center px-4 py-2 sm:px-5">
            <div role="separator" aria-label={label}>
                {/* Nothing here takes pointer events, the chip included. It abbreviates — `今日` does
                    not say which day it was — and every other shape that leaves something out offers
                    the whole value as a hover `title`. This one cannot: it lies over the rows, so
                    taking the hover means taking the tap from whatever is under it, and measured at
                    390px that is a link, covered at every sample point across the chip. The trade is
                    worse than it looks, because at 1280 the chip is not hit-testable at all — so the
                    title was never being offered where hovering exists, only taken where it does not.
                    The whole day stays reachable: `dateTime` carries it for machines, and each row's
                    own stamp is a `title` with the full date on it (components/timestamp). */}
                <time
                    aria-hidden
                    dateTime={day}
                    className="rounded-full border border-border bg-secondary px-3 py-0.5 text-xs text-secondary-foreground shadow-md"
                >
                    {label}
                </time>
            </div>
        </div>
    );
}
