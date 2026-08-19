/** What a conversation draws above a row, apart from the row itself. */
export type ChatSeparator = 'day' | 'unread';

/**
 * Which separators stand above a row, outermost first.
 *
 * The two meet most mornings: the first message a reader has not seen is usually also the first
 * message of that day, so the order is the ordinary case rather than an edge one. The day goes
 * outside because it is true for everyone — these rows were said on this date — while the unread line
 * is only this reader's place in them, and the narrower claim belongs nearer the rows it is about.
 *
 * A list of kinds rather than two booleans read in whatever order the markup happens to be written
 * in: the order is the decision, and a decision nothing states is one nothing can hold.
 */
/**
 * Whether what stands above the row already draws a line across the list, so the row must not draw
 * its own as well.
 *
 * Only the unread separator does: it is a ruled band, while the date heading is a pill floating in
 * the gap. Asking "is there anything above me" instead would leave the strongest break in the
 * conversation — the turn of a calendar day — as the one boundary with no line at all, weaker than
 * the hairline between two speakers of the same afternoon.
 *
 * The two are different shapes because they are different kinds of claim. A date heading says the rows
 * under it were said on that day, which is true for every reader. The unread line says where *this*
 * reader stopped — a claim so tied to its position that `dividerBeforeId` (lib/chat/unread) withdraws
 * it rather than draw it where the position cannot be shown honestly.
 */
export function drawsItsOwnRule(separators: readonly ChatSeparator[]): boolean {
    return separators.includes('unread');
}

export function separatorsAbove({ opensDay, isUnreadBoundary }: { opensDay: boolean; isUnreadBoundary: boolean }): ChatSeparator[] {
    const separators: ChatSeparator[] = [];

    if (opensDay) {
        separators.push('day');
    }

    if (isUnreadBoundary) {
        separators.push('unread');
    }

    return separators;
}
