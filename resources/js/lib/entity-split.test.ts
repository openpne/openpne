import assert from 'node:assert/strict';
import { test } from 'node:test';
import { splitEntities } from './entity-split.ts';

// Mirrors tests/Unit/Support/EntityTextTest.php case for case: the same bodies cut at the same code
// points. Escaping and autolinking are not tested here — <EntityText> defers them to React and to
// <UserText> — so these assert the raw segment shape only.

const alice = (offset: number, length: number, memberId = 7) => ({ memberId, offset, length });

test('an ascii mention becomes a mention segment between the surrounding text', () => {
    assert.deepEqual(splitEntities('hi @Alice there', [alice(3, 6)]), [
        { kind: 'text', text: 'hi ' },
        { kind: 'mention', text: '@Alice', memberId: 7 },
        { kind: 'text', text: ' there' },
    ]);
});

test('an astral emoji before the mention does not shift the range', () => {
    // U+1F600 is one code point but two UTF-16 units, so string indexing would cut it in half.
    assert.deepEqual(splitEntities('😀 hi @Alice', [alice(5, 6)]), [
        { kind: 'text', text: '😀 hi ' },
        { kind: 'mention', text: '@Alice', memberId: 7 },
        { kind: 'text', text: '' },
    ]);
});

test('a zwj emoji sequence counts as its code points', () => {
    // One rendered glyph, five code points: man + ZWJ + woman + ZWJ + girl.
    const family = '\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}';

    assert.deepEqual(splitEntities(`${family} @Alice`, [alice(6, 6)]), [
        { kind: 'text', text: `${family} ` },
        { kind: 'mention', text: '@Alice', memberId: 7 },
        { kind: 'text', text: '' },
    ]);
});

test('a url right after a mention stays in the text segment (linkified by UserText)', () => {
    assert.deepEqual(splitEntities('@Alice https://example.com/x', [alice(0, 6)]), [
        { kind: 'text', text: '' },
        { kind: 'mention', text: '@Alice', memberId: 7 },
        { kind: 'text', text: ' https://example.com/x' },
    ]);
});

test('a mention at the start of a line keeps the line break before it', () => {
    assert.deepEqual(splitEntities('line1\n@Alice', [alice(6, 6)]), [
        { kind: 'text', text: 'line1\n' },
        { kind: 'mention', text: '@Alice', memberId: 7 },
        { kind: 'text', text: '' },
    ]);
});

test('a mention at the end of the body leaves an empty trailing segment', () => {
    assert.deepEqual(splitEntities('hi @Alice', [alice(3, 6)]), [
        { kind: 'text', text: 'hi ' },
        { kind: 'mention', text: '@Alice', memberId: 7 },
        { kind: 'text', text: '' },
    ]);
});

test('two adjacent mentions render back to back', () => {
    assert.deepEqual(splitEntities('@Alice@Bob', [alice(0, 6), alice(6, 4, 8)]), [
        { kind: 'text', text: '' },
        { kind: 'mention', text: '@Alice', memberId: 7 },
        { kind: 'text', text: '' },
        { kind: 'mention', text: '@Bob', memberId: 8 },
        { kind: 'text', text: '' },
    ]);
});

test('a display name containing a url stays inside the mention segment', () => {
    // A member named "www.example.com": the range never reaches linkify, so no anchor nests here.
    assert.deepEqual(splitEntities('hi @www.example.com', [alice(3, 16)]), [
        { kind: 'text', text: 'hi ' },
        { kind: 'mention', text: '@www.example.com', memberId: 7 },
        { kind: 'text', text: '' },
    ]);
});

test('no entities leaves the body as the single text segment UserText already renders', () => {
    const body = 'see https://example.com/x\n<b>and</b> hi';

    assert.deepEqual(splitEntities(body, []), [{ kind: 'text', text: body }]);
    assert.deepEqual(splitEntities(null, []), [{ kind: 'text', text: '' }]);
    assert.deepEqual(splitEntities(undefined, []), [{ kind: 'text', text: '' }]);
});

test('a 140 code point body ending in a mention', () => {
    const body = `${'あ'.repeat(134)}@Alice`;
    assert.equal(Array.from(body).length, 140);

    assert.deepEqual(splitEntities(body, [alice(134, 6)]), [
        { kind: 'text', text: 'あ'.repeat(134) },
        { kind: 'mention', text: '@Alice', memberId: 7 },
        { kind: 'text', text: '' },
    ]);
});
