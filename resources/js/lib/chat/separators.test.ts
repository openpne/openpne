import assert from 'node:assert/strict';
import { test } from 'node:test';
import { separatorsAbove } from './separators.ts';

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
