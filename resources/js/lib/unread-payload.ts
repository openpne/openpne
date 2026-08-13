import type { TalkNavRooms, UnreadCounts } from '@/types';

/**
 * What `GET /unread-counts` answers with: the shell's badge counts, and the sidebar's room list read
 * in the same request.
 */
export interface UnreadPayload {
    unread: UnreadCounts;
    talkNavRooms: TalkNavRooms | null;
}

/**
 * Push one refresh into the shared props.
 *
 * Both move or neither does, and that is the whole reason this is a function rather than two calls at
 * the fetch site. The groups badge counts the rooms listed directly under it, so a refresh that
 * replaced the counts alone would leave a zeroed badge sitting above a row still claiming five
 * unread — the same contradiction on one screen, from one server read.
 */
export function applyUnreadPayload(payload: UnreadPayload, replaceProp: (key: string, value: unknown) => void): void {
    replaceProp('unread', payload.unread);
    // Null is a value here, not an absence: talk switched off between two reads has to clear the
    // rooms the sidebar is still holding.
    replaceProp('talkNavRooms', payload.talkNavRooms);
}
