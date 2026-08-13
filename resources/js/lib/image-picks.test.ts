import assert from 'node:assert/strict';
import { test } from 'node:test';
import { acceptPicks, MAX_POST_IMAGES } from './image-picks.ts';

test('a selection that fits is appended in pick order', () => {
    assert.deepEqual(acceptPicks(['a'], ['b', 'c']), { files: ['a', 'b', 'c'], refused: false });
});

test('the cap counts what is already held, not the pick alone', () => {
    assert.deepEqual(acceptPicks(['a', 'b'], ['c', 'd']), { files: ['a', 'b', 'c'], refused: true });
});

test('a full selection accepts nothing and says so', () => {
    assert.deepEqual(acceptPicks(['a', 'b', 'c'], ['d']), { files: ['a', 'b', 'c'], refused: true });
});

test('exactly filling the cap is not a refusal', () => {
    assert.deepEqual(acceptPicks([], ['a', 'b', 'c']), { files: ['a', 'b', 'c'], refused: false });
});

test('the default cap is the server contract', () => {
    assert.equal(MAX_POST_IMAGES, 3);
    assert.equal(acceptPicks<string>([], ['a', 'b', 'c', 'd']).files.length, MAX_POST_IMAGES);
});
