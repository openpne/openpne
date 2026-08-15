import assert from 'node:assert/strict';
import { test } from 'node:test';
import { markedName } from './identity-mark.ts';

/** Stands in for useT: renders the key with its replacements substituted, as Laravel would. */
const t = (key: string, replacements: Record<string, string> = {}): string =>
    Object.entries(replacements).reduce((line, [name, value]) => line.replaceAll(`:${name}`, value), key);

test('a human name comes back untouched — no marker, no translation lookup', () => {
    assert.equal(
        markedName('Ann', false, () => {
            throw new Error('a human name must not go through the marker phrase');
        }),
        'Ann',
    );
});

test("an AI account's name carries the marker", () => {
    assert.equal(markedName('Shirabe', true, t), 'Shirabe (AI)');
});

test('the marker phrase is the translated one, so ja can place it its own way', () => {
    const ja = (key: string, replacements: Record<string, string> = {}): string =>
        key === ':name (AI)' ? `${replacements.name}（AI）` : key;

    assert.equal(markedName('しらべ', true, ja), 'しらべ（AI）');
});

test('the fallback a withdrawn author is drawn with can be marked like any other name', () => {
    // RoomRow hands in whatever it will print, label included: the preview has one text slot.
    assert.equal(markedName('Withdrawn member', false, t), 'Withdrawn member');
});
