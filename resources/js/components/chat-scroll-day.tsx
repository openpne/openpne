import type { RefObject } from 'react';
import { useDateFormat } from '@/lib/use-date-format';
import { useSiteDay } from '@/lib/use-site-day';
import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

/**
 * The floating day indicator (docs/internals/group-talk.md, "Date headings, and what stands above a
 * row"). `aria-hidden` because it is a second copy of a heading the list already carries with
 * `role="separator"`, not because it is decorative.
 */
export function ChatScrollDay({ at, ref }: { at: string | null; ref: RefObject<HTMLDivElement | null> }) {
    const date = useDateFormat();
    // The label is `今日` for as long as today lasts, so it waits on the same clock the headings do.
    useSiteDay(usePage<PageProps>().props.timezone);

    return (
        // The same slot the unread banner takes, one occupant at a time: the page withholds this
        // while that is up rather than stacking them.
        <div
            ref={ref}
            aria-hidden
            className={cn(
                'group/day pointer-events-none sticky z-20 mb-0 flex h-0 items-start justify-center',
                // `items-start`, or the pill is stretched to the box's own zero height and its words
                // spill out of a four-pixel line.

                // At lg the place strip pins itself at the same offset and stands 45px tall, and
                // nothing publishes that height — so it is written here, and moves when the strip's
                // padding does.
                'top-[calc(var(--modern-top-offset)+0.5rem)] lg:top-[calc(var(--modern-top-offset)+3.5rem)]',
            )}
        >
            {at !== null && (
                <span
                    className={cn(
                        'rounded-full bg-muted px-3 py-0.5 text-xs text-muted-foreground shadow-sm',
                        'opacity-0 transition-opacity duration-150 motion-reduce:transition-none',
                        'group-data-[day=up]/day:opacity-100 group-data-[day=aside]/day:duration-0',
                    )}
                >
                    {date.dayHeading(at)}
                </span>
            )}
        </div>
    );
}
