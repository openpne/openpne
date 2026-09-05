import assert from 'node:assert/strict';
import { test } from 'node:test';
import { digestPlacement, dividerBeforeId, firstUnreadBoundary, readThroughBoundary } from './unread.ts';
import type { ChatStreamRow, ChatUnreadBoundary } from './types.ts';

const message = (id: number, createdAt: string): ChatStreamRow => ({
    id,
    createdAt,
    cursor: `${createdAt}|${id}`,
});

/** A talk's snapshot, through the adapter the page uses: a read cursor and how much sits past it. */
const readThrough = (count: number, at: string, id: number): ChatUnreadBoundary | null =>
    readThroughBoundary({ count, readThrough: { at, id }, cursor: `${at}|${id}` });

/** A conversation's snapshot: the oldest message still waiting. */
const firstUnread = (count: number, at: string, id: number): ChatUnreadBoundary | null =>
    firstUnreadBoundary({ count, firstUnread: { at, id }, cursor: `${at}|${id}` });

const conversation = [
    message(1, '2026-08-14T09:00:00+00:00'),
    message(2, '2026-08-14T09:01:00+00:00'),
    message(3, '2026-08-14T09:02:00+00:00'),
];

test('the line goes above the first message past the boundary', () => {
    const divider = dividerBeforeId(conversation, readThrough(2, '2026-08-14T09:00:00+00:00', 1), false);

    assert.equal(divider, 2);
});

test('a boundary at the newest message leaves no line to draw', () => {
    assert.equal(dividerBeforeId(conversation, readThrough(1, '2026-08-14T09:02:00+00:00', 3), false), null);
});

test('the id breaks a tie inside the same second', () => {
    const sameSecond = [
        message(7, '2026-08-14T09:00:00+00:00'),
        message(8, '2026-08-14T09:00:00+00:00'),
        message(9, '2026-08-14T09:00:00+00:00'),
    ];

    assert.equal(dividerBeforeId(sameSecond, readThrough(2, '2026-08-14T09:00:00+00:00', 7), false), 8);
});

test('the same instant written with a different offset is the same boundary', () => {
    // 09:00Z and 18:00+09:00 are one instant; only the id may separate them.
    assert.equal(dividerBeforeId(conversation, readThrough(2, '2026-08-14T18:00:00+09:00', 1), false), 2);
});

/** Every loaded row is unread, and rows remain further back. */
test('a boundary older than the loaded page draws no line', () => {
    assert.equal(dividerBeforeId(conversation, readThrough(60, '2026-08-14T08:00:00+00:00', 0), true), null);
});

test('the very first message of the conversation still takes a line', () => {
    // Nothing further back, so the first loaded row genuinely is the first unread one.
    assert.equal(dividerBeforeId(conversation, readThrough(3, '2026-08-14T08:00:00+00:00', 0), false), 1);
});

test('a reader with no cursor gets no line', () => {
    assert.equal(dividerBeforeId(conversation, readThroughBoundary(null), false), null);
});

test('a snapshot of zero never draws a line, however new the messages are', () => {
    assert.equal(dividerBeforeId(conversation, readThrough(0, '2026-08-14T09:00:00+00:00', 1), false), null);
});

test('messages arriving afterwards leave the line where it was', () => {
    const boundary = readThrough(2, '2026-08-14T09:00:00+00:00', 1);
    const arrived = [...conversation, message(4, '2026-08-14T09:03:00+00:00')];

    assert.equal(dividerBeforeId(conversation, boundary, false), 2);
    assert.equal(dividerBeforeId(arrived, boundary, false), 2);
});

test('an unparseable boundary neither throws nor scrambles the answer', () => {
    assert.equal(dividerBeforeId(conversation, readThrough(3, 'not a date', 0), false), 1);
});

test('a first-unread boundary draws the line above the row it names', () => {
    assert.equal(dividerBeforeId(conversation, firstUnread(2, '2026-08-14T09:01:00+00:00', 2), false), 2);
});

test('the two kinds read the same position differently', () => {
    const at = '2026-08-14T09:01:00+00:00';

    assert.equal(dividerBeforeId(conversation, readThrough(1, at, 2), false), 3);
    assert.equal(dividerBeforeId(conversation, firstUnread(2, at, 2), false), 2);
});

test('a first-unread boundary breaks a tie inside the same second by id', () => {
    const sameSecond = [
        message(7, '2026-08-14T09:00:00+00:00'),
        message(8, '2026-08-14T09:00:00+00:00'),
        message(9, '2026-08-14T09:00:00+00:00'),
    ];

    assert.equal(dividerBeforeId(sameSecond, firstUnread(2, '2026-08-14T09:00:00+00:00', 8), false), 8);
});

/** The banner's condition again: the oldest waiting message is behind what the page has loaded. */
test('a first-unread boundary older than the loaded page draws no line', () => {
    assert.equal(dividerBeforeId(conversation, firstUnread(60, '2026-08-14T08:00:00+00:00', 0), true), null);
});

test('a first-unread boundary at the head of an exhausted list still takes a line', () => {
    assert.equal(dividerBeforeId(conversation, firstUnread(3, '2026-08-14T09:00:00+00:00', 1), false), 1);
});

test('a conversation with nothing waiting has no boundary at all', () => {
    assert.equal(dividerBeforeId(conversation, firstUnreadBoundary(null), false), null);
});

test('the digest goes to the separator when the line is on the page', () => {
    assert.equal(digestPlacement(true, 2, false, false), 'divider');
});

test('the digest takes the place of the banner when the boundary is above the page', () => {
    assert.equal(digestPlacement(true, null, true, false), 'banner');
});

test('a boundary that is on the page never also draws the banner card', () => {
    assert.equal(digestPlacement(true, 2, true, false), 'divider');
});

test('a backlog too small for a digest draws no card in either place', () => {
    assert.equal(digestPlacement(false, 2, false, false), null);
    assert.equal(digestPlacement(false, null, true, false), null);
});

test('a spent catch-up leaves no card in either place', () => {
    assert.equal(digestPlacement(true, 2, false, true), null);
    assert.equal(digestPlacement(true, null, true, true), null);
});

test('nothing waiting is neither placement', () => {
    assert.equal(digestPlacement(true, null, false, false), null);
});
