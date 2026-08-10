import assert from 'node:assert/strict';
import { test } from 'node:test';
import { codePointLength, utf16ToCodePoint } from './code-points.ts';

// One glyph, one code point, two UTF-16 units — the gap this module exists to close.
const GRIN = '\u{1F600}';
// One glyph, five code points: man + ZWJ + woman + ZWJ + girl.
const FAMILY = '\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}';

test('an astral emoji counts as one code point, not two units', () => {
    assert.equal(`${GRIN}ab`.length, 4);
    assert.equal(codePointLength(`${GRIN}ab`), 3);
});

test('a zwj sequence counts as its code points', () => {
    assert.equal(codePointLength(FAMILY), 5);
});

test('a code-unit offset past an emoji converts to its code-point offset', () => {
    // "😀 @a": the "@" is at UTF-16 offset 3 and code-point offset 2.
    assert.equal(utf16ToCodePoint(`${GRIN} @a`, 3), 2);
});

test('offset 0 and the end of the string convert to the bounds', () => {
    const text = `${FAMILY} hi`;
    assert.equal(utf16ToCodePoint(text, 0), 0);
    assert.equal(utf16ToCodePoint(text, text.length), codePointLength(text));
});

test('an ascii body converts one for one', () => {
    assert.equal(utf16ToCodePoint('hello @a', 6), 6);
});
