import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    initial,
    markDeleted,
    mergeAfter,
    mergeBefore,
    mergeLatest,
    mergeSent,
    oldestBoundary,
    watermark,
} from './talk-stream-state.ts';
import type { TalkMessage, TalkPage } from './types.ts';

const message = (id: number, createdAt: string, body = `m${id}`): TalkMessage => ({
    id,
    body,
    createdAt,
    cursor: `${createdAt}|${id}`,
    author: null,
    isOwn: false,
    canDelete: false,
});

const page = (messages: TalkMessage[], hasOlder = false): TalkPage => ({ messages, hasOlder });

const bodies = (state: { messages: TalkMessage[] }) => state.messages.map((m) => m.body);

test('the initial page is held in tuple order', () => {
    const state = initial(page([message(1, '2026-08-13T09:00:00+00:00'), message(2, '2026-08-13T09:01:00+00:00')]));

    assert.deepEqual(bodies(state), ['m1', 'm2']);
    assert.equal(state.hasOlder, false);
});

test('a page that arrives out of order is sorted into tuple order', () => {
    const state = initial(page([message(2, '2026-08-13T09:01:00+00:00'), message(1, '2026-08-13T09:00:00+00:00')]));

    assert.deepEqual(bodies(state), ['m1', 'm2']);
});

test('the same message arriving twice is held once, and the later copy wins', () => {
    let state = initial(page([message(1, '2026-08-13T09:00:00+00:00')]));
    state = mergeAfter(state, page([{ ...message(1, '2026-08-13T09:00:00+00:00'), canDelete: true }]));

    assert.equal(state.messages.length, 1);
    assert.equal(state.messages[0]?.canDelete, true);
});

/**
 * The failure this reducer exists for: a send and a poll are in flight together, and the poll's
 * answer — which is older — lands second. Appending it would leave the list out of tuple order for
 * good, and the watermark would then be read off a row that is not the newest.
 */
test('a response completing out of order still lands in tuple order', () => {
    let state = initial(page([message(1, '2026-08-13T09:00:00+00:00')]));

    // The send returns first, with the newest message.
    state = mergeSent(state, message(3, '2026-08-13T09:02:00+00:00'));
    // The poll, asked before that send, answers afterwards with a message from in between.
    state = mergeAfter(state, page([message(2, '2026-08-13T09:01:00+00:00')]));

    assert.deepEqual(bodies(state), ['m1', 'm2', 'm3']);
    // And the watermark is the genuinely newest row, so the next poll asks from the right place.
    assert.equal(watermark(state), '2026-08-13T09:02:00+00:00|3');
});

test('same-second messages are ordered by id, not by arrival', () => {
    let state = initial(page([message(2, '2026-08-13T09:00:00+00:00')]));
    state = mergeAfter(state, page([message(1, '2026-08-13T09:00:00+00:00'), message(3, '2026-08-13T09:00:00+00:00')]));

    assert.deepEqual(bodies(state), ['m1', 'm2', 'm3']);
});

test('the same instant written with different offsets compares as the same instant', () => {
    // 09:00Z and 18:00+09:00 are one instant; only the id may separate them.
    let state = initial(page([message(2, '2026-08-13T18:00:00+09:00')]));
    state = mergeAfter(state, page([message(1, '2026-08-13T09:00:00+00:00')]));

    assert.deepEqual(bodies(state), ['m1', 'm2']);
});

/**
 * A poll already in flight when the delete landed answers from a snapshot that still holds the row.
 * Without the tombstone it would come back, and no later merge would take it away again.
 */
test('a deleted message is not re-admitted by a poll that was already in flight', () => {
    let state = initial(page([message(1, '2026-08-13T09:00:00+00:00'), message(2, '2026-08-13T09:01:00+00:00')]));

    state = markDeleted(state, 2);
    assert.deepEqual(bodies(state), ['m1']);

    // The stale snapshot arrives, still carrying the deleted row.
    state = mergeAfter(state, page([message(2, '2026-08-13T09:01:00+00:00'), message(3, '2026-08-13T09:02:00+00:00')]));

    assert.deepEqual(bodies(state), ['m1', 'm3'], 'the deleted message must stay gone');
});

test('the tombstone outlives every kind of merge', () => {
    let state = initial(page([message(2, '2026-08-13T09:01:00+00:00')]));
    state = markDeleted(state, 2);

    const revenant = message(2, '2026-08-13T09:01:00+00:00');
    state = mergeBefore(state, page([revenant]));
    state = mergeLatest(state, page([revenant]));
    state = mergeSent(state, revenant);

    assert.deepEqual(bodies(state), []);
});

/**
 * The empty-conversation poll asks for the newest page rather than after a watermark it does not
 * have. That page knows what lies behind it — dropping its answer would leave a busy group's history
 * unreachable until a reload.
 */
test('the latest-page fallback adopts hasOlder when the list was empty', () => {
    const state = mergeLatest(initial(page([])), page([message(51, '2026-08-13T09:50:00+00:00')], true));

    assert.equal(state.hasOlder, true);
    assert.deepEqual(bodies(state), ['m51']);
});

test('the latest-page fallback keeps the current hasOlder once the list is not empty', () => {
    // Reached the far end of the history already: the newest page alone must not undo that.
    let state = initial(page([message(1, '2026-08-13T09:00:00+00:00')], false));
    state = mergeLatest(state, page([message(2, '2026-08-13T09:01:00+00:00')], true));

    assert.equal(state.hasOlder, false);
});

test('the forward poll never revises hasOlder', () => {
    let state = initial(page([message(1, '2026-08-13T09:00:00+00:00')], true));
    state = mergeAfter(state, page([message(2, '2026-08-13T09:01:00+00:00')], false));

    assert.equal(state.hasOlder, true, 'reading forward says nothing about the far end');
});

test('load-older prepends and takes its hasOlder from the response', () => {
    let state = initial(page([message(5, '2026-08-13T09:05:00+00:00')], true));
    state = mergeBefore(state, page([message(3, '2026-08-13T09:03:00+00:00'), message(4, '2026-08-13T09:04:00+00:00')], false));

    assert.deepEqual(bodies(state), ['m3', 'm4', 'm5']);
    assert.equal(state.hasOlder, false);
    assert.equal(oldestBoundary(state), '2026-08-13T09:03:00+00:00|3');
});

test('the cursors of an empty conversation are undefined', () => {
    const state = initial(page([]));

    assert.equal(watermark(state), undefined);
    assert.equal(oldestBoundary(state), undefined);
});

test('a merge does not mutate the state it was given', () => {
    const before = initial(page([message(1, '2026-08-13T09:00:00+00:00')]));
    const after = mergeAfter(before, page([message(2, '2026-08-13T09:01:00+00:00')]));

    assert.deepEqual(bodies(before), ['m1']);
    assert.deepEqual(bodies(after), ['m1', 'm2']);
    assert.notEqual(before.messages, after.messages);
});

test('an unparseable stamp neither throws nor scrambles the rest', () => {
    let state = initial(page([message(2, '2026-08-13T09:01:00+00:00'), message(3, '2026-08-13T09:02:00+00:00')]));
    state = mergeAfter(state, page([message(1, 'not a date')]));

    assert.deepEqual(bodies(state), ['m1', 'm2', 'm3']);
});
