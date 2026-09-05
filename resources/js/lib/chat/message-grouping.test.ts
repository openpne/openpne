import assert from 'node:assert/strict';
import { test } from 'node:test';
import { continuesRun, foldsInto } from './message-grouping.ts';

const at = (iso: string, authorId: number | null) => ({
    author: authorId === null ? null : { id: authorId },
    createdAt: iso,
});

test('a quick follow-up by the same author continues the run', () => {
    assert.ok(continuesRun(at('2026-08-16T10:00:00+09:00', 1), at('2026-08-16T10:03:00+09:00', 1)));
});

test('the window closes at seven minutes', () => {
    assert.ok(continuesRun(at('2026-08-16T10:00:00+09:00', 1), at('2026-08-16T10:07:00+09:00', 1)));
    assert.ok(!continuesRun(at('2026-08-16T10:00:00+09:00', 1), at('2026-08-16T10:07:01+09:00', 1)));
});

test('another author starts a new run', () => {
    assert.ok(!continuesRun(at('2026-08-16T10:00:00+09:00', 1), at('2026-08-16T10:00:30+09:00', 2)));
});

test('withdrawn authors never group', () => {
    assert.ok(!continuesRun(at('2026-08-16T10:00:00+09:00', null), at('2026-08-16T10:00:30+09:00', null)));
    assert.ok(!continuesRun(at('2026-08-16T10:00:00+09:00', 1), at('2026-08-16T10:00:30+09:00', null)));
});

test('the first message of a page has no run to continue', () => {
    assert.ok(!continuesRun(undefined, at('2026-08-16T10:00:00+09:00', 1)));
});

test('a clock that runs backwards breaks the run', () => {
    assert.ok(!continuesRun(at('2026-08-16T10:05:00+09:00', 1), at('2026-08-16T10:00:00+09:00', 1)));
});

test('a reply breaks the run it would otherwise continue', () => {
    const m1 = { id: 1, ...at('2026-08-16T10:00:00+09:00', 7) };
    const reply = { id: 2, ...at('2026-08-16T10:01:00+09:00', 7), inReplyTo: { deleted: false } };

    assert.ok(!continuesRun(m1, reply));
    assert.ok(!foldsInto(m1, reply, false));
    const plain = { id: 3, ...at('2026-08-16T10:01:00+09:00', 7) };
    assert.ok(foldsInto(m1, plain, false));
});

test('anything drawn above a row restarts its run, and the rows after it fold below', () => {
    // One author, three quick messages, something drawn above the second: [m1] — sep — [m2] [m3].
    const m1 = { id: 1, ...at('2026-08-16T10:00:00+09:00', 7) };
    const m2 = { id: 2, ...at('2026-08-16T10:01:00+09:00', 7) };
    const m3 = { id: 3, ...at('2026-08-16T10:02:00+09:00', 7) };

    assert.ok(continuesRun(m1, m2));
    assert.ok(!foldsInto(m1, m2, true));
    assert.ok(foldsInto(m2, m3, false));
    assert.ok(foldsInto(m1, m2, false));
});
