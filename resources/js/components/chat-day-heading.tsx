import { useDateFormat } from '@/lib/use-date-format';
import { useSiteDay } from '@/lib/use-site-day';
import { usePage } from '@inertiajs/react';
import type { PageProps } from '@/types';

/**
 * The pill is here because components/chat-scroll-day.tsx reuses this shape, a floating copy having
 * to be legible over the words it covers (docs/internals/group-talk.md, "Date headings, and what
 * stands above a row"). An `<li>` carrying a `role="separator"` child rather than being one, since
 * an `<li role="separator">` is the one thing axe's `list` rule refuses.
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
        // A row under a separator adds no turn space of its own, so this is the only thing setting
        // either gap.

        // Named, because the scroll indicator asks where these are on every frame it measures, and
        // `li:has(> [role="separator"] time)` would be matched against every list item in the document.
        <li data-chat-day className="px-4 py-4 sm:px-5">
            <div role="separator" aria-label={label} className="flex items-center gap-3">
                <span aria-hidden className="h-px flex-1 bg-border" />
                {/* `title` because the visible text abbreviates, as every other shape that leaves
                    something out does; `dateTime` is what carries the value. */}
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
