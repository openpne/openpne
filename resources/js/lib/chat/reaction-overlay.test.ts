import assert from 'node:assert/strict';
import { test } from 'node:test';
import { chipsWithPending, isPending, noPending, withoutPending, withPending } from './reaction-overlay.ts';
import type { ChatReactionChip } from './types.ts';

const THUMBS = '\u{1F44D}';
const HEART = '\u{2764}\u{FE0F}';

const chip = (emoji: string, count: number, mine = false): ChatReactionChip => ({ emoji, count, mine });

const drawn = (chips: ChatReactionChip[], pending: ReturnType<typeof noPending>) => chipsWithPending(chips, pending, 1);

test('an emoji nobody holds is drawn as a new chip of one', () => {
    const pending = withPending(noPending(), 1, THUMBS, 'add');

    assert.deepEqual(drawn([], pending), [chip(THUMBS, 1, true)]);
});

test('an emoji others hold is drawn one higher and as the viewer’s own', () => {
    const pending = withPending(noPending(), 1, THUMBS, 'add');

    assert.deepEqual(drawn([chip(THUMBS, 2)], pending), [chip(THUMBS, 3, true)]);
});

test('taking one back drops the chip when it was the only one', () => {
    const pending = withPending(noPending(), 1, THUMBS, 'remove');

    assert.deepEqual(drawn([chip(THUMBS, 1, true)], pending), []);
    assert.deepEqual(drawn([chip(THUMBS, 3, true)], pending), [chip(THUMBS, 2)]);
});

test('a guess the row already agrees with changes nothing', () => {
    assert.deepEqual(drawn([chip(THUMBS, 2, true)], withPending(noPending(), 1, THUMBS, 'add')), [chip(THUMBS, 2, true)]);
    assert.deepEqual(drawn([chip(THUMBS, 2)], withPending(noPending(), 1, THUMBS, 'remove')), [chip(THUMBS, 2)]);
});

test('a guess made on another message is not drawn on this one', () => {
    const pending = withPending(noPending(), 2, THUMBS, 'add');

    assert.deepEqual(drawn([], pending), []);
});

/**
 * The reason this is an overlay rather than a snapshot to restore. While the tap was out, the poll
 * put a newer chip row underneath it — a refusal must give up the guess and nothing else, or it
 * would take back what someone else did in the meantime.
 */
test('a refused tap gives up its own guess and keeps what arrived while it was out', () => {
    const before = [chip(THUMBS, 1)];
    let pending = withPending(noPending(), 1, THUMBS, 'add');

    assert.deepEqual(drawn(before, pending), [chip(THUMBS, 2, true)]);

    // The poll replaces the row underneath: two more people reacted, and one added another emoji.
    const arrived = [chip(THUMBS, 3), chip(HEART, 1)];
    assert.deepEqual(drawn(arrived, pending), [chip(THUMBS, 4, true), chip(HEART, 1)]);

    pending = withoutPending(pending, 1, THUMBS);
    assert.deepEqual(drawn(arrived, pending), arrived, 'the failure costs the guess, not the news');
});

/**
 * Two chips tapped in a row, answered in the other order. Settling is by (message, emoji), so the
 * answer that came back first cannot take the other tap's guess off the screen with it.
 */
test('an answer settles its own tap and leaves another still out', () => {
    let pending = withPending(withPending(noPending(), 1, THUMBS, 'add'), 1, HEART, 'add');

    pending = withoutPending(pending, 1, HEART);

    assert.deepEqual(drawn([], pending), [chip(THUMBS, 1, true)]);
    assert.equal(isPending(pending, 1, THUMBS), true);
    assert.equal(isPending(pending, 1, HEART), false);
});

test('a chip with a tap already out refuses a second one', () => {
    const pending = withPending(noPending(), 1, THUMBS, 'add');

    assert.equal(isPending(pending, 1, THUMBS), true);
    assert.equal(isPending(pending, 1, HEART), false, 'another chip on the same message is free');
    assert.equal(isPending(pending, 2, THUMBS), false, 'and so is the same chip on another message');
});

test('settling a tap that is not out is the map it was given', () => {
    const pending = withPending(noPending(), 1, THUMBS, 'add');

    assert.equal(withoutPending(pending, 1, HEART), pending);
});

test('nothing out is the chips the stream holds', () => {
    const chips = [chip(THUMBS, 2)];

    assert.equal(drawn(chips, noPending()), chips);
});
