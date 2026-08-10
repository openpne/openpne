import '../components/compose/test-dom.ts';
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createSiteDayClock } from './use-site-day.ts';

// 2026-08-09T14:59:59Z is 2026-08-09 23:59:59 in Tokyo — one second before the site's day turns over,
// while a viewer in New York is still mid-afternoon on the 9th.
const beforeTokyoMidnight = new Date('2026-08-09T14:59:59Z');

test('a subscriber is notified when the site day turns over', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: beforeTokyoMidnight });
    const clock = createSiteDayClock();
    let notified = 0;
    clock.subscribe('Asia/Tokyo', () => {
        notified += 1;
    });

    t.mock.timers.tick(900);
    assert.equal(notified, 0, 'still the same site day');

    t.mock.timers.tick(200);
    assert.equal(notified, 1, 'the site day changed');
    assert.notEqual(clock.getSnapshot(), 0, 'the snapshot changed, so subscribers re-render');
});

test('it re-arms, so a page open for days keeps turning over', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: beforeTokyoMidnight });
    const clock = createSiteDayClock();
    let notified = 0;
    clock.subscribe('Asia/Tokyo', () => {
        notified += 1;
    });

    t.mock.timers.tick(1100); // first midnight
    t.mock.timers.tick(86_400_000); // the next one
    assert.equal(notified, 2);
});

// The boundary is the site's. A clock armed for New York must not fire when Tokyo rolls over.
test('the boundary follows the site zone, not the viewer or the host', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: beforeTokyoMidnight });
    const clock = createSiteDayClock();
    let notified = 0;
    clock.subscribe('America/New_York', () => {
        notified += 1;
    });

    t.mock.timers.tick(1100);
    assert.equal(notified, 0, 'Tokyo turned over; New York is still on the 9th');

    // 14:59:59Z is 10:59:59 in New York, so its own midnight is 46,801s away. Stopping at the
    // not-yet-fired assertion alone would pass for a clock that never fires at all.
    t.mock.timers.tick(46_801_000 - 1100);
    assert.equal(notified, 1, 'and it does fire at New York midnight');
});

test('the last unsubscribe disarms the timer', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: beforeTokyoMidnight });
    const clock = createSiteDayClock();
    let notified = 0;
    const unsubscribe = clock.subscribe('Asia/Tokyo', () => {
        notified += 1;
    });

    unsubscribe();
    t.mock.timers.tick(86_400_000);
    assert.equal(notified, 0);
});

// A background tab's timers are throttled or suspended, so the midnight wake can be missed entirely.
test('returning to the tab re-reads the clock', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: beforeTokyoMidnight });
    const clock = createSiteDayClock();
    let notified = 0;
    clock.subscribe('Asia/Tokyo', () => {
        notified += 1;
    });

    document.dispatchEvent(new Event('visibilitychange'));
    assert.equal(notified, 1, 'visible on return, so the day is re-read without waiting for the timer');
});
