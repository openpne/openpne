import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    applied,
    applyReaction,
    claimIntent,
    enterHistory,
    enterLatest,
    initial,
    markDeleted,
    mergeAfter,
    mergeBefore,
    mergeLatest,
    mergeNewer,
    mergeSent,
    mergeTouched,
    newIntents,
    oldestBoundary,
    retireIntents,
    isCurrentIntent,
    watermark,
} from './stream-state.ts';
import type { ChatPage, ChatStreamRow } from './types.ts';

interface TestMessage extends ChatStreamRow {
    body: string;
    canDelete: boolean;
}

const message = (id: number, createdAt: string, body = `m${id}`): TestMessage => ({
    id,
    body,
    createdAt,
    cursor: `${createdAt}|${id}`,
    canDelete: false,
});

const page = (messages: TestMessage[], hasOlder = false, hasNewer = false): ChatPage<TestMessage> => ({ messages, hasOlder, hasNewer });

const bodies = (state: { messages: TestMessage[] }) => state.messages.map((m) => m.body);

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

/** A send and a poll are in flight together, and the poll's answer — which is older — lands second. */
test('a response completing out of order still lands in tuple order', () => {
    let state = initial(page([message(1, '2026-08-13T09:00:00+00:00')]));

    // The send returns first, with the newest message.
    state = mergeSent(state, message(3, '2026-08-13T09:02:00+00:00'));
    // The poll, asked before that send, answers afterwards with a message from in between.
    state = mergeAfter(state, page([message(2, '2026-08-13T09:01:00+00:00')]));

    assert.deepEqual(bodies(state), ['m1', 'm2', 'm3']);
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
 * have.
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

/** A deep link (`?m=`) is rendered with the slice its message sits in, not with the newest page. */
test('a page rendered around a deep link opens in the history window', () => {
    const landed = initial(page([message(10, '2026-08-13T09:10:00+00:00'), message(11, '2026-08-13T09:11:00+00:00')], true, true));

    assert.deepEqual(landed.window, { kind: 'history', hasNewer: true });
    assert.equal(landed.hasOlder, true);
    assert.deepEqual(bodies(landed), ['m10', 'm11']);
});

test('a deep link whose page already runs to the newest message is the live window', () => {
    assert.deepEqual(initial(page([message(90, '2026-08-13T18:00:00+00:00')], true, false)).window, { kind: 'latest' });
});

/**
 * The page holds the move until a render whose generation has moved past the one it was asked from,
 * so a landing that starts in history has to move the generation like any other window change.
 */
test('leaving a deep link window moves the generation the jump was asked from', () => {
    const landed = initial(page([message(10, '2026-08-13T09:10:00+00:00')], true, true));
    assert.equal(landed.window.kind, 'history', 'the landing this is about is one behind the newest message');
    const askedFrom = landed.generation;

    const back = enterLatest(landed, page([message(90, '2026-08-13T18:00:00+00:00')], true, false));
    assert.notEqual(back.generation, askedFrom);

    // And stepping forward page by page arrives at the same place.
    const caughtUp = mergeNewer(landed, page([message(11, '2026-08-13T09:11:00+00:00')], false, false));
    assert.deepEqual(caughtUp.window, { kind: 'latest' });
    assert.notEqual(caughtUp.generation, askedFrom);
});

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
 * The poll is on the wire when the reader taps the unread banner, and both reads were issued against
 * the live window.
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

test('a send is refused by a history window and taken by the live one', () => {
    const history = enterHistory(initial(page([])), page([message(10, '2026-08-13T09:10:00+00:00')], true, true));
    const mine = message(99, '2026-08-13T18:30:00+00:00');

    assert.equal(mergeSent(history, mine), history, 'it would sit under a message it does not answer');

    const back = enterLatest(history, page([message(90, '2026-08-13T18:00:00+00:00')], true));
    assert.deepEqual(bodies(mergeSent(back, mine)), ['m90', 'm99']);
});

/**
 * The composer stays live while a jump is still out, so the reader can ask to be taken back through
 * history and then write instead.
 */
test('a send made while a jump is still out keeps the reader at the live end', () => {
    const intents = newIntents();
    let state = initial(page([message(90, '2026-08-13T18:00:00+00:00')], true));
    const at = state.generation;

    // The reader taps the unread banner…
    const jump = claimIntent(intents);

    // …then writes before its page arrives, and the write is acknowledged first.
    retireIntents(intents);
    state = mergeSent(state, message(99, '2026-08-13T18:05:00+00:00'));

    // The jump's page finally lands.
    if (isCurrentIntent(intents, jump)) {
        state = applied(state, at, (current) => enterHistory(current, page([message(10, '2026-08-13T09:10:00+00:00')], true, true)));
    }

    assert.deepEqual(state.window, { kind: 'latest' }, 'writing puts you back at the live end');
    assert.deepEqual(bodies(state), ['m90', 'm99'], 'and your own words stay where you wrote them');
});

/** The control: nothing retired the jump, so its page is still the one the reader is waiting for. */
test('a jump nobody overtook still lands', () => {
    const intents = newIntents();
    const state = initial(page([message(90, '2026-08-13T18:00:00+00:00')], true));
    const jump = claimIntent(intents);

    assert.equal(isCurrentIntent(intents, jump), true);
    const jumped = applied(state, state.generation, (current) => enterHistory(current, page([message(10, '2026-08-13T09:10:00+00:00')], true, true)));
    assert.deepEqual(bodies(jumped), ['m10']);
});

test('the later of two moves is the one waited for', () => {
    const intents = newIntents();
    const first = claimIntent(intents);
    const second = claimIntent(intents);

    assert.equal(isCurrentIntent(intents, first), false);
    assert.equal(isCurrentIntent(intents, second), true);
});

/** A chip row, as the serializer sends one. */
const chip = (emoji: string, count: number, mine = false) => ({ emoji, count, mine });

const chipsOf = (state: { messages: TestMessage[] }, id: number) => state.messages.find((m) => m.id === id)?.reactions;

test('a touched row replaces the copy held, chips and all', () => {
    const state = initial(page([message(1, '2026-08-14T09:00:00+00:00'), message(2, '2026-08-14T09:01:00+00:00')]));
    const touched = { ...message(2, '2026-08-14T09:01:00+00:00'), reactions: [chip('\u{1F44D}', 1, true)] };

    const merged = mergeTouched(state, [touched], 7);

    assert.deepEqual(bodies(merged), ['m1', 'm2'], 'reacting moves nothing in the keyset order');
    assert.deepEqual(chipsOf(merged, 2), [chip('\u{1F44D}', 1, true)]);
    assert.equal(merged.reactionsVersion, 7);
});

test('a touched row the list does not hold is not inserted', () => {
    const state = initial(page([message(2, '2026-08-14T09:01:00+00:00')], true));

    const merged = mergeTouched(state, [{ ...message(1, '2026-08-14T09:00:00+00:00'), reactions: [chip('\u{1F44D}', 1)] }], 7);

    assert.deepEqual(bodies(merged), ['m2']);
    assert.equal(merged.reactionsVersion, 7, 'and the watermark still moves past it');
});

test('a touched row for a message deleted in this session stays gone', () => {
    const state = markDeleted(initial(page([message(1, '2026-08-14T09:00:00+00:00'), message(2, '2026-08-14T09:01:00+00:00')])), 2);

    const merged = mergeTouched(state, [{ ...message(2, '2026-08-14T09:01:00+00:00'), reactions: [chip('\u{1F44D}', 1)] }], 7);

    assert.deepEqual(bodies(merged), ['m1']);
});

test('a poll that touched nothing and moved no watermark returns the very state it was given', () => {
    const state = { ...initial(page([message(1, '2026-08-14T09:00:00+00:00')])), reactionsVersion: 7 };

    assert.equal(mergeTouched(state, [], 7), state);
    assert.equal(mergeTouched(state, [], undefined), state, 'a conversation with no reactions is answered without one');
});

test('a poll answered after the window moved moves neither the rows nor the watermark', () => {
    const state = { ...initial(page([message(1, '2026-08-14T09:00:00+00:00')])), reactionsVersion: 7 };
    const at = state.generation;
    const moved = enterHistory(state, page([message(10, '2026-08-13T09:10:00+00:00')], true, true));

    const after = applied(moved, at, (current) =>
        mergeTouched(current, [{ ...message(1, '2026-08-14T09:00:00+00:00'), reactions: [chip('\u{1F44D}', 1)] }], 9),
    );

    assert.equal(after, moved);
    assert.equal(after.reactionsVersion, 7, 'so the next poll asks again from where the list actually stands');
});

test('a window change carries the watermark across', () => {
    const state = { ...initial(page([message(1, '2026-08-14T09:00:00+00:00')])), reactionsVersion: 7 };

    assert.equal(enterHistory(state, page([message(10, '2026-08-13T09:10:00+00:00')], true, true)).reactionsVersion, 7);
    assert.equal(enterLatest(state, page([message(1, '2026-08-14T09:00:00+00:00')])).reactionsVersion, 7);
});

test('a write moves one row and leaves a row it does not name alone', () => {
    const state = initial(page([message(1, '2026-08-14T09:00:00+00:00'), message(2, '2026-08-14T09:01:00+00:00')]));

    const moved = applyReaction(state, 2, '\u{1F44D}', 'add');

    assert.deepEqual(chipsOf(moved, 2), [chip('\u{1F44D}', 1, true)]);
    assert.equal(chipsOf(moved, 1), undefined);
    assert.equal(applyReaction(state, 404, '\u{1F44D}', 'add'), state, 'a row the list no longer holds is nothing to move');
});

/**
 * The poll delivers later changes and moves the watermark past them before the write's own answer
 * lands.
 */
test('a write answered after the poll has moved the row on cannot take the row back', () => {
    const state = { ...initial(page([message(1, '2026-08-14T09:00:00+00:00')])), reactionsVersion: 7 };

    // The poll gets there first, carrying both the viewer's own add and the one made after it.
    const polled = mergeTouched(state, [{ ...message(1, '2026-08-14T09:00:00+00:00'), reactions: [chip('\u{1F44D}', 2, true)] }], 9);

    // …and the answer to the viewer's own tap, sent when the watermark stood at 7, arrives late.
    const settled = applyReaction(polled, 1, '\u{1F44D}', 'add', 7);

    assert.deepEqual(chipsOf(settled, 1), [chip('\u{1F44D}', 2, true)]);
    assert.equal(settled.reactionsVersion, 9, 'and the watermark stays where the poll left it');
});

test('a late add cannot resurrect what the viewer took back from another tab', () => {
    const state = { ...initial(page([message(1, '2026-08-14T09:00:00+00:00')])), reactionsVersion: 7 };

    // The other tab removed the emoji after this tab's add committed; the poll delivered the
    // server's final word — the viewer no longer holds it.
    const polled = mergeTouched(state, [{ ...message(1, '2026-08-14T09:00:00+00:00'), reactions: [chip('\u{1F44D}', 2, false)] }], 9);

    const settled = applyReaction(polled, 1, '\u{1F44D}', 'add', 7);

    assert.equal(settled, polled, 'the stale answer moves nothing, own flag included');
});

test('a late remove cannot take back what the viewer re-added from another tab', () => {
    const state = { ...initial(page([message(1, '2026-08-14T09:00:00+00:00')])), reactionsVersion: 7 };

    const polled = mergeTouched(state, [{ ...message(1, '2026-08-14T09:00:00+00:00'), reactions: [chip('\u{1F44D}', 3, true)] }], 9);

    const settled = applyReaction(polled, 1, '\u{1F44D}', 'remove', 7);

    assert.equal(settled, polled, 'the stale answer moves nothing, own flag included');
});
