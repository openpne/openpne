import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    applied,
    enterHistory,
    enterLatest,
    initial,
    markDeleted,
    mergeAfter,
    mergeBefore,
    mergeLatest,
    mergeNewer,
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
    mentions: [],
    images: [],
    isOwn: false,
    canDelete: false,
});

const page = (messages: TalkMessage[], hasOlder = false, hasNewer = false): TalkPage => ({ messages, hasOlder, hasNewer });

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

/**
 * The failure this identity exists for: the poll rebuilds state every tick, and each new
 * `messages` reference re-runs the page's pin effect — a scrollTo re-issued every 8s fights
 * iOS's keyboard pan and shoves the sticky composer out of view.
 */
test('an idle poll returns the very state it was given', () => {
    const before = initial(page([message(1, '2026-08-13T09:00:00+00:00')], true));

    assert.equal(mergeAfter(before, page([])), before);
    assert.equal(mergeBefore(before, page([], true)), before);
});

test('an empty page that changes hasOlder is not a no-op', () => {
    const before = initial(page([message(1, '2026-08-13T09:00:00+00:00')], true));
    const after = mergeBefore(before, page([], false));

    assert.notEqual(after, before);
    assert.equal(after.hasOlder, false);
});

test('a page opens on the live end of the conversation', () => {
    assert.deepEqual(initial(page([message(1, '2026-08-13T09:00:00+00:00')])).window, { kind: 'latest' });
});

/**
 * The whole point of the window: the unread jump lands somewhere behind the newest message, and what
 * it lands on must not be stirred into what was on screen. The two stretches have a hole between
 * them, and a merged list would draw that hole as a conversation.
 */
test('the unread jump replaces the list rather than merging into it', () => {
    const held = initial(page([message(90, '2026-08-13T18:00:00+00:00'), message(91, '2026-08-13T18:01:00+00:00')], true));

    const jumped = enterHistory(held, page([message(10, '2026-08-13T09:10:00+00:00'), message(11, '2026-08-13T09:11:00+00:00')], true, true));

    assert.deepEqual(bodies(jumped), ['m10', 'm11'], 'nothing from the live window survives the jump');
    assert.deepEqual(jumped.window, { kind: 'history', hasNewer: true });
    assert.equal(jumped.hasOlder, true);
});

test('a jump whose page already runs to the newest message is the live window', () => {
    const held = initial(page([message(9, '2026-08-13T09:09:00+00:00')], true));

    assert.deepEqual(enterHistory(held, page([message(8, '2026-08-13T09:08:00+00:00')], true, false)).window, { kind: 'latest' });
});

test('load-newer folds a contiguous page in and stays behind while more remain', () => {
    let state = enterHistory(initial(page([])), page([message(10, '2026-08-13T09:10:00+00:00')], true, true));
    state = mergeNewer(state, page([message(11, '2026-08-13T09:11:00+00:00')], false, true));

    assert.deepEqual(bodies(state), ['m10', 'm11']);
    assert.deepEqual(state.window, { kind: 'history', hasNewer: true });
    assert.equal(state.hasOlder, true, 'reading forward says nothing about the far end');
});

test('load-newer that exhausts the conversation returns to the live window', () => {
    let state = enterHistory(initial(page([])), page([message(10, '2026-08-13T09:10:00+00:00')], true, true));
    state = mergeNewer(state, page([message(11, '2026-08-13T09:11:00+00:00')], false, false));

    assert.deepEqual(state.window, { kind: 'latest' }, 'the list now runs to the newest message');
    assert.deepEqual(bodies(state), ['m10', 'm11']);
});

test('returning to the live end replaces the stretch being read', () => {
    const held = enterHistory(initial(page([])), page([message(10, '2026-08-13T09:10:00+00:00')], true, true));

    const back = enterLatest(held, page([message(90, '2026-08-13T18:00:00+00:00')], true, false));

    assert.deepEqual(bodies(back), ['m90']);
    assert.deepEqual(back.window, { kind: 'latest' });
});

/** A session's deletions are the one thing a window change carries over. */
test('a tombstone survives every window change', () => {
    let state = initial(page([message(2, '2026-08-13T09:01:00+00:00')]));
    state = markDeleted(state, 2);

    const revenant = message(2, '2026-08-13T09:01:00+00:00');
    state = enterHistory(state, page([revenant], true, true));
    assert.deepEqual(bodies(state), []);

    state = mergeNewer(state, page([revenant], false, true));
    state = enterLatest(state, page([revenant]));

    assert.deepEqual(bodies(state), []);
});

test('an idle poll still returns the very state it was given', () => {
    const before = enterHistory(initial(page([])), page([message(10, '2026-08-13T09:10:00+00:00')], true, true));

    assert.equal(mergeAfter(before, page([])), before);
});

/**
 * The race the generation exists for. The poll is on the wire when the reader taps the unread
 * banner, and both reads were issued against the live window — whichever lands second must not be
 * folded into a list that has moved on.
 */
test('a poll answered after the jump landed is thrown away', () => {
    const live = initial(page([message(90, '2026-08-13T18:00:00+00:00'), message(91, '2026-08-13T18:01:00+00:00')], true));
    const at = live.generation;

    // The jump answers first and the list stands on a stretch of history.
    let state = applied(live, at, (current) =>
        enterHistory(current, page([message(10, '2026-08-13T09:10:00+00:00'), message(11, '2026-08-13T09:11:00+00:00')], true, true)),
    );
    // The poll, asked of the live window before any of that, answers afterwards.
    state = applied(state, at, (current) => mergeAfter(current, page([message(92, '2026-08-13T18:02:00+00:00')])));

    assert.deepEqual(bodies(state), ['m10', 'm11'], 'no live row may be spliced across the gap');
    assert.deepEqual(state.window, { kind: 'history', hasNewer: true });
    assert.equal(watermark(state), '2026-08-13T09:11:00+00:00|11', 'and the watermark must not jump the gap either');
});

test('a poll answered before the jump landed still lands in the live window', () => {
    const live = initial(page([message(90, '2026-08-13T18:00:00+00:00')], true));
    const at = live.generation;

    let state = applied(live, at, (current) => mergeAfter(current, page([message(91, '2026-08-13T18:01:00+00:00')])));
    assert.deepEqual(bodies(state), ['m90', 'm91'], 'the live window is still the one that asked');

    state = applied(state, at, (current) => enterHistory(current, page([message(10, '2026-08-13T09:10:00+00:00')], true, true)));

    assert.deepEqual(bodies(state), ['m10']);
    assert.deepEqual(state.window, { kind: 'history', hasNewer: true });
});

test('every window change retires the reads the old list had out', () => {
    const live = initial(page([message(1, '2026-08-13T09:00:00+00:00')], true));
    const at = live.generation;

    const history = enterHistory(live, page([message(10, '2026-08-13T09:10:00+00:00')], true, true));
    assert.notEqual(history.generation, at);

    const back = enterLatest(history, page([message(90, '2026-08-13T18:00:00+00:00')], true));
    assert.notEqual(back.generation, history.generation);

    // Catching up through "load newer" is the same kind of change, so it counts too.
    const caughtUp = mergeNewer(history, page([message(11, '2026-08-13T09:11:00+00:00')], false, false));
    assert.deepEqual(caughtUp.window, { kind: 'latest' });
    assert.notEqual(caughtUp.generation, history.generation);
});

test('a load-older answered after the window moved is thrown away', () => {
    const live = initial(page([message(90, '2026-08-13T18:00:00+00:00')], true));
    const at = live.generation;

    let state = applied(live, at, (current) => enterHistory(current, page([message(10, '2026-08-13T09:10:00+00:00')], true, true)));
    state = applied(state, at, (current) => mergeBefore(current, page([message(89, '2026-08-13T17:59:00+00:00')], true)));

    assert.deepEqual(bodies(state), ['m10']);
});

/** A read that outlived nothing is just a read; the guard must not swallow the ordinary case. */
test('a response from the list that asked for it is folded in', () => {
    const live = initial(page([message(1, '2026-08-13T09:00:00+00:00')]));

    const state = applied(live, live.generation, (current) => mergeAfter(current, page([message(2, '2026-08-13T09:01:00+00:00')])));

    assert.deepEqual(bodies(state), ['m1', 'm2']);
});

/**
 * The sent message is held to the live window rather than to a generation: it belongs at the foot of
 * the list the caller re-read to get back there, but never under a stretch of history.
 */
test('a send is refused by a history window and taken by the live one', () => {
    const history = enterHistory(initial(page([])), page([message(10, '2026-08-13T09:10:00+00:00')], true, true));
    const mine = message(99, '2026-08-13T18:30:00+00:00');

    assert.equal(mergeSent(history, mine), history, 'it would sit under a message it does not answer');

    const back = enterLatest(history, page([message(90, '2026-08-13T18:00:00+00:00')], true));
    assert.deepEqual(bodies(mergeSent(back, mine)), ['m90', 'm99']);
});
