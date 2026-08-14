import { instantOf } from './stream-state.ts';
import type { ChatStreamRow, ChatUnreadSnapshot } from './types';

/**
 * Where the "unread from here" divider goes: above the first message past the boundary the page was
 * rendered with. Derived from the snapshot alone, so the line stays put while the poll delivers and
 * mark-read moves the stored cursor out from under it.
 *
 * Null means "not in this list", and the two ways of getting there are different states on screen:
 * everything held is already read (nothing to mark), or the boundary lies further back than the
 * loaded page reaches (the banner, which jumps to it). The caller tells them apart by the snapshot's
 * count — a boundary with messages behind it that is not here has to be off-page.
 */
export function dividerBeforeId<M extends ChatStreamRow>(
    messages: readonly M[],
    snapshot: ChatUnreadSnapshot | null,
    hasOlder: boolean,
): number | null {
    // A visit that opened with nothing unread has no boundary at all, and messages arriving while
    // the reader watches are not a backlog — drawing a line under them mid-session would say they
    // missed something they are in fact reading.
    if (snapshot === null || snapshot.count === 0) {
        return null;
    }

    const at = instantOf(snapshot.readThrough.at);
    const index = messages.findIndex((message) => {
        const messageAt = instantOf(message.createdAt);

        return messageAt > at || (messageAt === at && message.id > snapshot.readThrough.id);
    });

    if (index === -1) {
        return null;
    }

    // The first loaded row being unread proves nothing when older rows exist: the ones above it may
    // be unread too, and a line at the page edge would mark pagination rather than the boundary.
    if (index === 0 && hasOlder) {
        return null;
    }

    return messages[index]?.id ?? null;
}
