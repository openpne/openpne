import assert from 'node:assert/strict';
import { test } from 'node:test';
import { applyUnreadPayload, type UnreadPayload } from './unread-payload.ts';

const counts = { friendRequests: 0, unreadMessages: 0, notifications: 0, groupTalks: 1 };

const room = { id: 7, name: 'Book club', imageUrl: null, unread: 5, muted: false };

/** Collect what a refresh writes, in order. */
const applied = (payload: UnreadPayload): [string, unknown][] => {
    const writes: [string, unknown][] = [];
    applyUnreadPayload(payload, (key, value) => writes.push([key, value]));

    return writes;
};

test('one response replaces both props', () => {
    const payload: UnreadPayload = { unread: counts, talkNavRooms: { rooms: [room], hasMore: false } };

    assert.deepEqual(applied(payload), [
        ['unread', counts],
        ['talkNavRooms', payload.talkNavRooms],
    ]);
});

/**
 * The failure this exists to stop: the badge counts the rooms listed under it, so a refresh must
 * never move one without the other. A read taken after the room was opened says zero in both places,
 * and both places have to hear it.
 */
test('a refresh that zeroes the badge zeroes the room it was counting', () => {
    const read: UnreadPayload = {
        unread: { ...counts, groupTalks: 0 },
        talkNavRooms: { rooms: [{ ...room, unread: 0 }], hasMore: false },
    };
    const writes = new Map(applied(read));

    assert.equal((writes.get('unread') as typeof counts).groupTalks, 0);
    assert.equal((writes.get('talkNavRooms') as { rooms: { unread: number }[] }).rooms[0]?.unread, 0);
});

test('a null room list is written, not skipped', () => {
    // Talk switched off between two reads: the sidebar must lose the rooms it is still holding.
    assert.deepEqual(applied({ unread: counts, talkNavRooms: null }), [
        ['unread', counts],
        ['talkNavRooms', null],
    ]);
});
