import assert from 'node:assert/strict';
import { test } from 'node:test';
import { arrivalsAfter } from './arrivals.ts';

const row = (id: number, at: string) => ({ id, cursor: String(id), createdAt: at });

const conversation = [
    row(10, '2026-08-16T10:00:00+09:00'),
    row(11, '2026-08-16T10:01:00+09:00'),
    row(12, '2026-08-16T10:02:00+09:00'),
];

test('a reader standing at the newest message is behind nothing', () => {
    assert.equal(arrivalsAfter(conversation, { at: '2026-08-16T10:02:00+09:00', id: 12 }), 0);
});

test('what arrived after the mark is counted, and what came before it is not', () => {
    assert.equal(arrivalsAfter(conversation, { at: '2026-08-16T10:00:00+09:00', id: 10 }), 2);
    assert.equal(arrivalsAfter(conversation, { at: '2026-08-16T10:01:00+09:00', id: 11 }), 1);
});

test('a reader whose position is unknown is told a number about nobody', () => {
    // The `?m=` landing that never reached the foot: nothing here is known to have been read.
    assert.equal(arrivalsAfter(conversation, null), 0);
});

test('the mark survives the deletion of the message it was made on', () => {
    const withoutTheMarked = conversation.filter((message) => message.id !== 10);

    assert.equal(arrivalsAfter(withoutTheMarked, { at: '2026-08-16T10:00:00+09:00', id: 10 }), 2);
});

test('within one second the id breaks the tie, the way the conversation itself is ordered', () => {
    const sameSecond = [
        row(20, '2026-08-16T10:00:00+09:00'),
        row(21, '2026-08-16T10:00:00+09:00'),
        row(22, '2026-08-16T10:00:00+09:00'),
    ];

    assert.equal(arrivalsAfter(sameSecond, { at: '2026-08-16T10:00:00+09:00', id: 20 }), 2);
    assert.equal(arrivalsAfter(sameSecond, { at: '2026-08-16T10:00:00+09:00', id: 22 }), 0);
});

test('a smaller id is not an older message', () => {
    // Upgraded conversations carry ids that do not run with the clock.
    const migrated = [
        row(900, '2026-08-16T10:00:00+09:00'),
        row(7, '2026-08-16T10:05:00+09:00'),
    ];

    assert.equal(arrivalsAfter(migrated, { at: '2026-08-16T10:00:00+09:00', id: 900 }), 1);
    assert.equal(arrivalsAfter(migrated, { at: '2026-08-16T10:05:00+09:00', id: 7 }), 0);
});
