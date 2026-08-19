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
