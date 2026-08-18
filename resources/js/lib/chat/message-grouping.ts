/** What grouping needs to know about a message — the stream rows all carry these. */
export interface GroupableMessage {
    author: { id: number } | null;
    createdAt: string;
    /**
     * Present when this row answers another message. A reply opens a new turn even inside a run: the
     * header must return to draw the reference above it. Talk carries it; a direct message omits it.
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
 * Whether `message` continues `previous`'s run and can drop its author header. Withdrawn authors
 * (null) never group: two nameless rows in a row would read as one member's, and nothing on the row
 * could say otherwise.
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
 * Whether `message` folds under the previous row's header, in a list where the unread separator is
 * drawn above the row whose id is `dividerBeforeId` (lib/chat/unread). That row always restarts a
 * run — the separator is where the reader resumes, and it must say again who is speaking. The rows
 * after it fold normally: they and their run sit below the line together, so nothing folds across it.
 */
export function foldsInto(
    previous: (GroupableMessage & { id: number }) | undefined,
    message: GroupableMessage & { id: number },
    dividerBeforeId: number | null,
): boolean {
    return message.id !== dividerBeforeId && continuesRun(previous, message);
}
