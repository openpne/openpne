import { instantOf } from './stream-state.ts';
import type { ChatStreamRow } from './types';

/**
 * The newest message a reader has actually stood at the foot of, as a position rather than a row.
 *
 * A position, because the row it names can leave: a message deleted while the reader is scrolled up
 * would take the mark with it, and a count derived from "is the marked row still in the list" would
 * drop to zero without anything having been read.
 */
export interface ChatSeenMark {
    at: string;
    id: number;
}

/**
 * How many of the loaded messages arrived after the reader was last at the foot.
 *
 * The comparison is the conversation's own order — the instant, with the id breaking a tie inside
 * the same second — which is what `dividerBeforeId` compares against too, so the page has one rule
 * for "after" rather than two. Bare id order is not that rule: migrated ids are not monotonic in
 * time (lib/chat/mark-read), so a larger id does not mean a later message.
 *
 * Null is a reader whose position is not known: a visit that landed mid-conversation on a `?m=`
 * link and never reached the foot has read nothing this page can name, and a count would be a claim
 * about them rather than an observation.
 */
export function arrivalsAfter(messages: readonly ChatStreamRow[], seen: ChatSeenMark | null): number {
    if (seen === null) {
        return 0;
    }

    const at = instantOf(seen.at);

    return messages.filter((message) => {
        const messageAt = instantOf(message.createdAt);

        return messageAt === at ? message.id > seen.id : messageAt > at;
    }).length;
}
