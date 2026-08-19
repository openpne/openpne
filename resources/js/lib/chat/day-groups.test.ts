import assert from 'node:assert/strict';
import { test } from 'node:test';
import { groupByDay } from './day-groups.ts';

/** Stands in for the site's calendar day: whatever precedes the `T`. */
const dayOf = (iso: string): string | null => (iso.includes('T') ? (iso.split('T')[0] ?? null) : null);

const at = (iso: string) => ({ createdAt: iso, id: iso });

test('a conversation of one day is one group', () => {
    const groups = groupByDay([at('2026-08-12T10:00'), at('2026-08-12T11:00')], dayOf);

    assert.equal(groups.length, 1);
    assert.equal(groups[0]?.day, '2026-08-12');
    assert.equal(groups[0]?.messages.length, 2);
});

test('each day gets its own group, in order', () => {
    const groups = groupByDay([at('2026-08-12T23:00'), at('2026-08-13T00:30'), at('2026-08-13T09:00'), at('2026-08-14T08:00')], dayOf);

    assert.deepEqual(
        groups.map((g) => [g.day, g.messages.length]),
        [
            ['2026-08-12', 1],
            ['2026-08-13', 2],
            ['2026-08-14', 1],
        ],
    );
});

test('the order within a group is the order it arrived in', () => {
    const groups = groupByDay([at('2026-08-12T10:00'), at('2026-08-12T11:00'), at('2026-08-12T12:00')], dayOf);

    assert.deepEqual(
        groups[0]?.messages.map((m) => m.createdAt),
        ['2026-08-12T10:00', '2026-08-12T11:00', '2026-08-12T12:00'],
    );
});

test('an empty conversation has no groups', () => {
    assert.deepEqual(groupByDay([], dayOf), []);
});

test('a message whose date will not parse stays in the run it arrived in', () => {
    // A heading over it could only name nothing, so it does not open a day of its own.
    const groups = groupByDay([at('2026-08-12T10:00'), at(''), at('2026-08-12T11:00')], dayOf);

    assert.equal(groups.length, 1);
    assert.equal(groups[0]?.messages.length, 3);
});

test('a day that comes back later opens a second group rather than rejoining the first', () => {
    // Only ever a clock running backwards, but the grouping follows the list rather than re-sorting it:
    // a heading names the run under it, and moving rows to another run would move what was said.
    const groups = groupByDay([at('2026-08-12T10:00'), at('2026-08-13T10:00'), at('2026-08-12T23:00')], dayOf);

    assert.deepEqual(
        groups.map((g) => g.day),
        ['2026-08-12', '2026-08-13', '2026-08-12'],
    );
});
