/** What grouping needs of a message: when it was said. */
export interface DatedMessage {
    createdAt: string;
}

/** One calendar day of a conversation, and the messages said on it, in the order they arrived. */
export interface DayGroup<M> {
    /** The site's calendar day, as `Y-m-d` — the value the heading carries and the key of the group. */
    day: string;
    messages: M[];
}

/**
 * A conversation cut into its calendar days.
 *
 * The list is grouped rather than flat because that is what makes the date heading able to stick: a
 * sticky element stops at the edge of its containing block, so a heading inside its own day is pushed
 * out by the next day's, while flat siblings all stop at the same offset and pile up there — the
 * second heading painting over the first, and a narrower label leaving the wider one behind it showing
 * at both ends.
 *
 * `dayOf` is passed in rather than imported: the site's calendar day comes from `lib/date.ts`, which
 * only `useDateFormat` may import (eslint), so the page hands in the reading it already has.
 */
export function groupByDay<M extends DatedMessage>(messages: readonly M[], dayOf: (iso: string) => string | null): DayGroup<M>[] {
    const groups: DayGroup<M>[] = [];

    for (const message of messages) {
        // A message whose date will not parse keeps the day of the run it arrived in rather than
        // opening one of its own — a heading over it could only name nothing.
        const day = dayOf(message.createdAt) ?? groups[groups.length - 1]?.day ?? '';
        const open = groups[groups.length - 1];

        if (open !== undefined && open.day === day) {
            open.messages.push(message);
        } else {
            groups.push({ day, messages: [message] });
        }
    }

    return groups;
}
