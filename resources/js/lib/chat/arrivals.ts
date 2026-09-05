import { instantOf } from './stream-state.ts';
import type { ChatStreamRow } from './types';

/**
 * A position rather than a row, because the row it names can leave: a message deleted while the
 * reader is scrolled up would take the mark with it. It is a read-through position — the last row
 * the reader stood at — which is what makes the comparison below exclusive.
 */
export interface ChatSeenMark {
    at: string;
    id: number;
}

/**
 * The comparison is the conversation's own order, not bare id order: migrated ids are not monotonic
 * in time. Null is a reader whose position is not known — an anchored `?m=` visit that never reached
 * the foot — and the branch goes quiet if such a visit ever opens in the live window.
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
