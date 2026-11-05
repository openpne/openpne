import assert from 'node:assert/strict';
import { test } from 'node:test';
import { computeInitial, markedName } from './identity-mark.ts';

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

// The same table as tests/Unit/Support/InitialTest.php: the favicon and the brand mark must agree.
test('the initial is computed in code points, the same way the server does', () => {
    const table: [string, string][] = [
        ['My SNS', 'MY'],
        ['Acme', 'AC'],
        ['日本語', '日'],
        ['', '??'],
        ['  ', '??'],
        ['\u3000全角空白先頭', '全'],
        ['🎉party', '🎉P'],
        ['a', 'A'],
        ['\uFEFFAcme', 'AC'],
        ['\u0085Acme', '\u0085A'],
        ['\u180EAcme', '\u180EA'],
    ];
    for (const [name, initial] of table) {
        assert.equal(computeInitial(name), initial, JSON.stringify(name));
    }
});
