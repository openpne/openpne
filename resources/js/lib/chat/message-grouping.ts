/** What grouping needs to know about a message — the stream rows all carry these. */
export interface GroupableMessage {
    author: { id: number } | null;
    createdAt: string;
}

/**
 * Discord's window: a run of messages by one author within seven minutes reads as one turn of
 * speaking, so the repeats drop their header. Beyond it the header returns even mid-run — a reply
 * after a silence is a new turn, not more of the last one.
 */
const WINDOW_MS = 7 * 60 * 1000;

/**
 * Whether `message` continues `previous`'s run and can drop its author header. Withdrawn authors
 * (null) never group: two nameless rows in a row would read as one member's, and nothing on the row
 * could say otherwise.
 */
export function continuesRun(previous: GroupableMessage | undefined, message: GroupableMessage): boolean {
    if (previous?.author == null || message.author == null || previous.author.id !== message.author.id) {
        return false;
    }

    const gap = Date.parse(message.createdAt) - Date.parse(previous.createdAt);

    return gap >= 0 && gap <= WINDOW_MS;
}
