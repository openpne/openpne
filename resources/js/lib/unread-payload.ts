import type { TalkNavRooms, UnreadCounts } from '@/types';

/** What `GET /unread-counts` answers with, in one read. */
export interface UnreadPayload {
    unread: UnreadCounts;
    talkNavRooms: TalkNavRooms | null;
}

/**
 * Both move or neither does: the groups badge counts the rooms listed directly under it, so a
 * refresh that replaced the counts alone would leave a zeroed badge above a row still claiming five.
 */
export function applyUnreadPayload(payload: UnreadPayload, replaceProp: (key: string, value: unknown) => void): void {
    replaceProp('unread', payload.unread);
    // Null is a value here, not an absence: talk switched off between two reads has to clear the
    // rooms the sidebar is still holding.
    replaceProp('talkNavRooms', payload.talkNavRooms);
}
