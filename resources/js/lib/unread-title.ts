/**
 * The document title's unread-notification prefix.
 *
 * The count lives in a module store rather than React state because the consumer is Inertia's title
 * callback (app.tsx), which the head manager runs on its own schedule — it is not a `usePage`
 * consumer, so a re-render alone would never reach it. Setting `document.title` from an effect
 * instead is unreliable: the head manager's DOM writes are debounced, so a flush queued before the
 * effect lands after it and overwrites the title.
 */

/** Counts above this render as `99+`, matching the badge pill (see UnreadPill). */
const CLAMP = 99;

/** A prefix this module wrote: `(3) ` or `(99+) `. Anchored, so parentheses elsewhere survive. */
const PREFIX = /^\(\d+\+?\) /;

let current = 0;

export function setUnreadTitleCount(count: number): void {
    current = count;
}

export function unreadTitleCount(): number {
    return current;
}

/** Idempotent: an already-prefixed title has its prefix replaced, never stacked. */
export function withUnreadPrefix(title: string, count: number): string {
    const bare = title.replace(PREFIX, '');

    return count > 0 ? `(${count > CLAMP ? `${CLAMP}+` : count}) ${bare}` : bare;
}
