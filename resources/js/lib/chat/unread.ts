import { instantOf } from './stream-state.ts';
import type { ChatFirstUnreadSnapshot, ChatStreamRow, ChatUnreadBoundary, ChatUnreadSnapshot } from './types';

/**
 * Derived from the render-time boundary alone, so the line stays put while the poll delivers and
 * mark-read moves the stored read state out from under it (docs/internals/group-talk.md, "The
 * divider is a snapshot, and the banner is what it cannot draw"). The caller tells the two causes
 * of a null apart by what its snapshot said was waiting.
 */
export function dividerBeforeId<M extends ChatStreamRow>(
    messages: readonly M[],
    boundary: ChatUnreadBoundary | null,
    hasOlder: boolean,
): number | null {
    if (boundary === null) {
        return null;
    }

    const at = instantOf(boundary.at);
    // The line goes above the row after a readThrough position, and above the row a firstUnread
    // position names.
    const index = messages.findIndex((message) => {
        const messageAt = instantOf(message.createdAt);

        if (messageAt !== at) {
            return messageAt > at;
        }

        return boundary.kind === 'firstUnread' ? message.id >= boundary.id : message.id > boundary.id;
    });

    if (index === -1) {
        return null;
    }

    // A line against the top edge would mark where pagination stopped rather than where reading did,
    // so the banner's jump reopens the position with its context instead.
    if (index === 0 && hasOlder) {
        return null;
    }

    return messages[index]?.id ?? null;
}

/**
 * A count of zero is no boundary at all: the cursor is sent whether or not anything is waiting, and
 * messages arriving while the reader watches are not a backlog.
 */
export function readThroughBoundary(snapshot: ChatUnreadSnapshot | null): ChatUnreadBoundary | null {
    if (snapshot === null || snapshot.count === 0) {
        return null;
    }

    return { kind: 'readThrough', ...snapshot.readThrough };
}

/** Absent when nothing is waiting — no row, no position. */
export function firstUnreadBoundary(snapshot: ChatFirstUnreadSnapshot | null): ChatUnreadBoundary | null {
    return snapshot === null ? null : { kind: 'firstUnread', ...snapshot.firstUnread };
}

export type DigestPlacement = 'divider' | 'banner' | null;

/**
 * One expression is what keeps the two placements exclusive: deciding them separately would let a
 * boundary in between draw two cards, or one that is neither draw none. The digest is a render-time
 * snapshot, so `spent` is how the page that took the catch-up withdraws the card.
 */
export function digestPlacement(hasDigest: boolean, dividerId: number | null, hasBacklog: boolean, spent: boolean): DigestPlacement {
    if (!hasDigest || spent) {
        return null;
    }

    if (dividerId !== null) {
        return 'divider';
    }

    return hasBacklog ? 'banner' : null;
}
