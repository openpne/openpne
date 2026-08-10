import assert from 'node:assert/strict';
import { test } from 'node:test';
import { nextRelativeRefreshDelay } from './date.ts';

/**
 * The hook itself is four lines around this delay, so what is worth pinning is that following the
 * delay actually lands on the moment the text changes — the property the hook depends on and the one a
 * future edit could quietly break.
 */
const textAt = (at: string, now: Date, timeZone = 'Asia/Tokyo') => {
    const elapsed = Math.max(0, now.getTime() - new Date(at).getTime());

    if (elapsed < 60_000) return 'now';
    if (elapsed < 3_600_000) return `minute:${Math.floor(elapsed / 60_000)}`;
    if (elapsed < 86_400_000) return `hour:${Math.floor(elapsed / 3_600_000)}`;

    return `day:${timeZone}`;
};

test('waking after the delay always lands on a changed text', () => {
    const at = '2026-08-10T12:00:00Z';

    for (const offsetMs of [0, 30_000, 59_999, 60_000, 90_000, 3_599_999, 3_600_000, 5_400_000, 86_399_999]) {
        const now = new Date(new Date(at).getTime() + offsetMs);
        const delay = nextRelativeRefreshDelay(at, now);
        assert.notEqual(delay, null, `expected a scheduled wake at +${offsetMs}ms`);

        const before = textAt(at, now);
        const justBefore = textAt(at, new Date(now.getTime() + (delay as number) - 1));
        const after = textAt(at, new Date(now.getTime() + (delay as number)));

        assert.equal(justBefore, before, `text changed before the wake at +${offsetMs}ms`);
        assert.notEqual(after, before, `text unchanged at the wake at +${offsetMs}ms`);
    }
});

// Past a day the only thing that changes the text is the calendar rolling over, and the shared day
// clock is already waiting on that; arming here too would be a second timer for the same moment.
test('past a day it arms nothing and leaves the boundary to the day clock', () => {
    const at = '2026-08-10T12:00:00Z';

    assert.equal(nextRelativeRefreshDelay(at, new Date('2026-08-11T12:00:00Z')), null);
    assert.equal(nextRelativeRefreshDelay(at, new Date('2026-08-20T12:00:00Z')), null);
});

test('an unusable value arms nothing rather than spinning', () => {
    assert.equal(nextRelativeRefreshDelay('', new Date('2026-08-10T12:00:00Z')), null);
});
