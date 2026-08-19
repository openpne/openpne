import assert from 'node:assert/strict';
import { test } from 'node:test';
import { drawsItsOwnRule, separatorsAbove } from './separators.ts';

test('a row with nothing above it gets nothing', () => {
    assert.deepEqual(separatorsAbove({ opensDay: false, isUnreadBoundary: false }), []);
});

test('each separator is drawn on its own when only it applies', () => {
    assert.deepEqual(separatorsAbove({ opensDay: true, isUnreadBoundary: false }), ['day']);
    assert.deepEqual(separatorsAbove({ opensDay: false, isUnreadBoundary: true }), ['unread']);
});

test('where they meet, the day is outside the unread line', () => {
    // The morning case: the first message of the day is also the first the reader has not seen.
    assert.deepEqual(separatorsAbove({ opensDay: true, isUnreadBoundary: true }), ['day', 'unread']);
});

test('only the unread band is a line; a row under a date heading keeps its own', () => {
    // Otherwise the turn of a calendar day is the one boundary in the conversation with nothing drawn
    // across it — weaker than the hairline between two speakers of the same afternoon.
    assert.equal(drawsItsOwnRule(separatorsAbove({ opensDay: true, isUnreadBoundary: false })), false);
    assert.equal(drawsItsOwnRule(separatorsAbove({ opensDay: false, isUnreadBoundary: true })), true);
    // Where they meet, the band is still there and is still the line.
    assert.equal(drawsItsOwnRule(separatorsAbove({ opensDay: true, isUnreadBoundary: true })), true);
    assert.equal(drawsItsOwnRule(separatorsAbove({ opensDay: false, isUnreadBoundary: false })), false);
});
