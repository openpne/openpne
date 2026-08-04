import assert from 'node:assert/strict';
import { test } from 'node:test';
import { nextDirection, type ScrollState } from './use-scroll-direction.ts';

const THRESHOLD = 8;

/** Feed a run of positions through the reducer, starting at rest at the top. */
const run = (positions: number[], from: ScrollState = { direction: 'up', anchorY: 0 }): ScrollState =>
    positions.reduce((state, y) => nextDirection(state, y, THRESHOLD), from);

test('a slow scroll accumulates into a flip', () => {
    // Every step is below the threshold on its own; measured from the anchor they add up.
    assert.equal(run([3, 6, 9, 12]).direction, 'down');
});

test('jitter below the threshold holds the direction', () => {
    assert.equal(run([3, 6, 4, 7, 2]).direction, 'up');

    const down = run([40]);
    assert.equal(down.direction, 'down');
    assert.equal(run([36, 38, 34], down).direction, 'down');
});

test('travel in the current direction re-anchors, so a reversal is measured from where it ended', () => {
    const down = run([100, 200, 300]);

    assert.deepEqual(down, { direction: 'down', anchorY: 300 });
    // 292 is far from the flip that happened at 100, but only 8 back from where the run stopped.
    assert.equal(run([292], down).direction, 'up');
});

test('the top of the page is always up', () => {
    const down = run([100]);

    assert.deepEqual(nextDirection(down, 0, THRESHOLD), { direction: 'up', anchorY: 0 });
    // Overscroll (a negative y on iOS) counts as the top too.
    assert.deepEqual(nextDirection(down, -20, THRESHOLD), { direction: 'up', anchorY: 0 });
});

test('a threshold-sized move flips, one short of it does not', () => {
    assert.equal(nextDirection({ direction: 'up', anchorY: 100 }, 108, THRESHOLD).direction, 'down');
    assert.equal(nextDirection({ direction: 'up', anchorY: 100 }, 107, THRESHOLD).direction, 'up');
    assert.equal(nextDirection({ direction: 'down', anchorY: 100 }, 92, THRESHOLD).direction, 'up');
    assert.equal(nextDirection({ direction: 'down', anchorY: 100 }, 93, THRESHOLD).direction, 'down');
});
