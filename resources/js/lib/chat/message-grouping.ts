export interface GroupableMessage {
    author: { id: number } | null;
    createdAt: string;
    /**
     * A reply opens a new turn even inside a run, because the header must return to draw the
     * reference above it. Talk carries it; a direct message omits it.
     */
    inReplyTo?: { deleted: boolean } | null;
}

/**
 * A run of messages by one author within seven minutes reads as one turn of speaking, so the repeats
 * drop their header. Beyond it the header returns even mid-run — a reply after a silence is a new
 * turn, not more of the last one.
 */
const WINDOW_MS = 7 * 60 * 1000;

/**
 * Withdrawn authors (null) never group: two nameless rows in a row would read as one member's, and
 * nothing on the row could say otherwise.
 */
export function continuesRun(previous: GroupableMessage | undefined, message: GroupableMessage): boolean {
    // A reply opens a new turn: its own header carries the reference, so it never folds under the row
    // before it even when the same author spoke seconds earlier.
    if (message.inReplyTo != null) {
        return false;
    }

    if (previous?.author == null || message.author == null || previous.author.id !== message.author.id) {
        return false;
    }

    const gap = Date.parse(message.createdAt) - Date.parse(previous.createdAt);

    return gap >= 0 && gap <= WINDOW_MS;
}

/**
 * `restartsHere` is the caller's one fact for every reason a row opens a turn regardless of who spoke
 * last — an unread separator, a date heading — and such a row says again who is speaking. The rows
 * after it fold normally, so nothing folds across it.
 */
export function foldsInto(previous: GroupableMessage | undefined, message: GroupableMessage, restartsHere: boolean): boolean {
    return !restartsHere && continuesRun(previous, message);
}
