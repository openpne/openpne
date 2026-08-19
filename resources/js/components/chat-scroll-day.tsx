import type { RefObject } from 'react';
import { useDateFormat } from '@/lib/use-date-format';
import { useSiteDay } from '@/lib/use-site-day';
import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

/**
 * The day the reader is in, drawn over the conversation while they are moving through it.
 *
 * A long day pushes its heading off the screen, and then nothing on the page answers "which day am I
 * reading". This answers it — and stops answering half a second after the scrolling does, because a
 * reader who has settled is reading the words, not looking for a date. That is why it does not have to
 * solve overlapping with anything: while it is up, nobody is reading underneath it.
 *
 * It is the heading's pill and nothing else. The heading is a pill on a rule; take the rule away and
 * what is left is this, so the two land on each other when a heading scrolls up past the line, rather
 * than needing to be swapped one for the other the way a client with two different shapes would.
 *
 * The other half of that pairing is in chat-day-heading.tsx: the pill's background belongs to the
 * heading because this reuses the shape, not because the heading needs a background of its own.
 *
 * `aria-hidden`, and not because it is decorative — because it is a second copy. The heading it echoes
 * is in the list with `role="separator"`, and a reader who is told the boundary does not also need to
 * be told it again from a floating box whose whole behaviour is tied to a scroll they may not be
 * making.
 */
export function ChatScrollDay({ at, visible, ref }: { at: string | null; visible: boolean; ref: RefObject<HTMLDivElement | null> }) {
    const date = useDateFormat();
    // The label is `今日` for as long as today lasts, so it waits on the same clock the headings do.
    useSiteDay(usePage<PageProps>().props.timezone);

    return (
        // Zero height and `mb-0`: it floats over the conversation and must cost it nothing, and zero
        // height alone does not give that — the page frame puts a margin under every child but the
        // last, and this one is mounted for the life of the screen so it would carry that margin for
        // the life of the screen (tests/Feature/Frontend/ZeroHeightMarginGuardTest).
        //
        // The same slot the unread banner takes. One slot, one occupant: the banner is a standing
        // offer and this is a passing note, so the page withholds this while that is up rather than
        // stacking them — which would tie this element's position to the banner's height, and the
        // banner is a button or a card depending on how much was missed.
        <div
            ref={ref}
            aria-hidden
            className="pointer-events-none sticky top-[calc(var(--modern-top-offset)+0.5rem)] z-20 mb-0 flex h-0 justify-center"
        >
            {at !== null && (
                <span
                    className={cn(
                        'rounded-full bg-muted px-3 py-0.5 text-xs text-muted-foreground shadow-sm',
                        'transition-opacity duration-150 motion-reduce:transition-none',
                        visible ? 'opacity-100' : 'opacity-0',
                    )}
                >
                    {date.dayHeading(at)}
                </span>
            )}
        </div>
    );
}
