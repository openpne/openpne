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

/** Which boundary affordance carries a catch-up digest, or none. */
export type DigestPlacement = 'divider' | 'banner' | null;

/**
 * Where the digest goes on this render.
 *
 * The two placements are the boundary's two states, and a single expression is what makes them
 * exclusive: a divider id means the line is on the page, and the banner exists exactly when a
 * backlog has no line to draw. Deciding them separately would let a boundary that is neither draw no
 * card at all, or one in between draw two.
 *
 * `spent` is the catch-up having been taken. The digest is a render-time snapshot like the divider
 * beside it — nothing re-reads it mid-visit — so the card is withdrawn by the page that spent it
 * rather than by a number changing underneath.
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
