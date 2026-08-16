import assert from 'node:assert/strict';
import { test } from 'node:test';
import { continuesRun } from './message-grouping.ts';

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
