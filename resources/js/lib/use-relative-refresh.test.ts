import assert from 'node:assert/strict';
import { test } from 'node:test';
import { msUntilNextSiteDay, relativeDeadline, relativeParts } from './date.ts';

/**
 * Compared through the production formatter, since a second copy of the bucket rules could agree with
 * itself while both drifted from the screen. Two boundaries share the work, so asserting only against
 * the deadline would fail whenever the site's midnight legitimately arrives first.
 */
const shown = (at: string, now: Date, timeZone: string) => JSON.stringify(relativeParts(at, timeZone, now));

const assertNoChangeIsMissed = (at: string, now: Date, timeZone: string, label: string) => {
    const deadline = relativeDeadline(at, timeZone, now);
    assert.notEqual(deadline, null, `${label}: expected a scheduled wake`);

    const dayBoundary = now.getTime() + msUntilNextSiteDay(now, timeZone);
    const firstBoundary = Math.min(deadline as number, dayBoundary);

    const before = shown(at, now, timeZone);

    assert.equal(shown(at, new Date(firstBoundary - 1), timeZone), before, `${label}: text changed before either timer`);
    assert.notEqual(shown(at, new Date(deadline as number), timeZone), before, `${label}: text unchanged at the wake`);
};

test('waking at the deadline always lands on a changed text', () => {
    const at = '2026-08-10T12:00:00Z';

    for (const offsetMs of [0, 30_000, 59_999, 60_000, 90_000, 3_599_999, 3_600_000, 5_400_000, 86_399_999]) {
        assertNoChangeIsMissed(at, new Date(new Date(at).getTime() + offsetMs), 'Asia/Tokyo', `+${offsetMs}ms`);
    }
});

/**
 * A fall-back day is 25 hours, so 24 hours elapsed can still be the same calendar day — where "0 days
 * ago" would be a reading of nothing. The hour bucket carries it, which means it also has a deadline to
 * keep.
 */
test('a day longer than 24 hours stays on hours and keeps scheduling', () => {
    const zone = 'America/New_York';
    const at = '2026-11-01T04:30:00Z'; // 2026-11-01 00:30 EDT

    // 24 hours later it is 23:30 on the same local day.
    const sameDayAt24h = new Date('2026-11-02T04:30:00Z');
    assert.deepEqual(relativeParts(at, zone, sameDayAt24h), { unit: 'hour', count: 24 });
    assertNoChangeIsMissed(at, sameDayAt24h, zone, 'hour 24 on a 25-hour day');

    // An hour on, the calendar has turned over and it becomes a day.
    assert.deepEqual(relativeParts(at, zone, new Date('2026-11-02T05:30:00Z')), { unit: 'day', count: 1 });
});

/** Antarctica/Troll shifts by two hours at once, so 2026-10-25 there is 26 hours long. */
test('a 26-hour day stays on hours for both of the extra ones', () => {
    const zone = 'Antarctica/Troll';
    const at = '2026-10-24T22:30:00Z'; // 2026-10-25 00:30 local

    assert.deepEqual(relativeParts(at, zone, new Date('2026-10-25T22:30:00Z')), { unit: 'hour', count: 24 });
    assert.deepEqual(relativeParts(at, zone, new Date('2026-10-25T23:30:00Z')), { unit: 'hour', count: 25 });
    assertNoChangeIsMissed(at, new Date('2026-10-25T23:30:00Z'), zone, 'hour 25 on a 26-hour day');

    // And only once the local date turns over does it become a day.
    assert.deepEqual(relativeParts(at, zone, new Date('2026-10-26T00:30:00Z')), { unit: 'day', count: 1 });
});

// A clock running ahead of ours should wake once, when its text really changes — not once a minute
// until it catches up.
test('a future instant schedules one wake at the moment it stops being just now', () => {
    const at = '2026-08-11T12:00:00Z';
    const now = new Date('2026-08-10T12:00:00Z');

    assert.deepEqual(relativeParts(at, 'Asia/Tokyo', now), { unit: 'now' });
    assert.equal(relativeDeadline(at, 'Asia/Tokyo', now), new Date(at).getTime() + 60_000);
});

test('anything counted in days arms nothing and leaves the boundary to the day clock', () => {
    const at = '2026-08-10T12:00:00Z';

    for (const now of ['2026-08-11T13:30:00Z', '2026-08-12T13:30:00Z', '2026-08-16T13:30:00Z']) {
        const parts = relativeParts(at, 'Asia/Tokyo', new Date(now));
        assert.equal(parts?.unit, 'day', `${now}: expected the day bucket`);
        assert.equal(relativeDeadline(at, 'Asia/Tokyo', new Date(now)), null, `${now}: armed a timer anyway`);
    }

    assert.equal(relativeDeadline(at, 'Asia/Tokyo', new Date('2026-08-20T12:00:00Z')), null); // past a week
    assert.equal(relativeDeadline('', 'Asia/Tokyo', new Date(at)), null);
});
