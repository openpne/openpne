import { instantOf } from './stream-state.ts';
import type { ChatFirstUnreadSnapshot, ChatStreamRow, ChatUnreadBoundary, ChatUnreadSnapshot } from './types';

/**
 * Where the "unread from here" divider goes: above the first message the boundary the page was
 * rendered with puts on the unread side. Derived from that boundary alone, so the line stays put
 * while the poll delivers and mark-read moves the stored read state out from under it.
 *
 * Null means "not in this list", and the two ways of getting there are different states on screen:
 * everything held is already read (nothing to mark), or the boundary lies further back than the
 * loaded page reaches (the banner, which jumps to it). The caller tells them apart by what its
 * snapshot said was waiting — a boundary with messages behind it that is not here has to be off-page.
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
    // A readThrough position names the last row read, so the line goes above the row after it; a
    // firstUnread position names the first row not read, so the line goes above that row itself.
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

    // A line drawn against the top edge of the page marks where pagination stopped rather than where
    // reading did: for a readThrough boundary the rows above may be unread too, and for a
    // firstUnread one the reader would land on the boundary with none of the conversation above it.
    // The banner's jump reopens the position with its context instead.
    if (index === 0 && hasOlder) {
        return null;
    }

    return messages[index]?.id ?? null;
}

/**
 * A read cursor as a boundary. A count of zero is no boundary at all: the cursor is sent whether or
 * not anything is waiting, and messages arriving while the reader watches are not a backlog —
 * drawing a line under them mid-session would say they missed something they are in fact reading.
 */
export function readThroughBoundary(snapshot: ChatUnreadSnapshot | null): ChatUnreadBoundary | null {
    if (snapshot === null || snapshot.count === 0) {
        return null;
    }

    return { kind: 'readThrough', ...snapshot.readThrough };
}

/** The oldest waiting message as a boundary. Absent when nothing is waiting — no row, no position. */
export function firstUnreadBoundary(snapshot: ChatFirstUnreadSnapshot | null): ChatUnreadBoundary | null {
    return snapshot === null ? null : { kind: 'firstUnread', ...snapshot.firstUnread };
}
