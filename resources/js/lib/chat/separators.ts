export type ChatSeparator = 'day' | 'unread';

/**
 * Outermost first: the day goes outside the unread line, because the day is true for everyone while
 * the line is only this reader's place among them (docs/internals/group-talk.md, "Date headings, and
 * what stands above a row").
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
