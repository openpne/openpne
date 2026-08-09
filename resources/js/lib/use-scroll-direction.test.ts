import assert from 'node:assert/strict';
import { test } from 'node:test';
import { nextDirection, type PageDirection, renderedDirection, type ScrollState } from './use-scroll-direction.ts';

const LIMITS = { threshold: 8, minDownY: 44 };

/** Feed a run of positions through the reducer, starting at rest at the top. */
const run = (positions: number[], from: ScrollState = { direction: 'up', anchorY: 0 }): ScrollState =>
    positions.reduce((state, y) => nextDirection(state, y, LIMITS), from);

/** Reading position, past the point where the bar has scrolled away. */
const deep: ScrollState = { direction: 'up', anchorY: 400 };

test('a slow scroll accumulates into a flip', () => {
    // Every step is below the threshold on its own; measured from the anchor they add up.
    assert.equal(run([403, 406, 409, 412], deep).direction, 'down');
});

test('jitter below the threshold holds the direction', () => {
    assert.equal(run([403, 406, 404, 407, 402], deep).direction, 'up');

    const down = run([440], deep);
    assert.equal(down.direction, 'down');
    assert.equal(run([436, 438, 434], down).direction, 'down');
});

test('travel in the current direction re-anchors, so a reversal is measured from where it ended', () => {
    const down = run([100, 200, 300]);

    assert.deepEqual(down, { direction: 'down', anchorY: 300 });
    // 292 is far from the flip that happened at 100, but only 8 back from where the run stopped.
    assert.equal(run([292], down).direction, 'up');
});

test('the top of the page is always up', () => {
    const down = run([100]);

    assert.deepEqual(nextDirection(down, 0, LIMITS), { direction: 'up', anchorY: 0 });
    // Overscroll (a negative y on iOS) counts as the top too.
    assert.deepEqual(nextDirection(down, -20, LIMITS), { direction: 'up', anchorY: 0 });
});

test('a threshold-sized move flips, one short of it does not', () => {
    assert.equal(nextDirection({ direction: 'up', anchorY: 100 }, 108, LIMITS).direction, 'down');
    assert.equal(nextDirection({ direction: 'up', anchorY: 100 }, 107, LIMITS).direction, 'up');
    assert.equal(nextDirection({ direction: 'down', anchorY: 100 }, 92, LIMITS).direction, 'up');
    assert.equal(nextDirection({ direction: 'down', anchorY: 100 }, 93, LIMITS).direction, 'down');
});

test('the first flip down waits for the bar to have scrolled away', () => {
    // Threshold travel alone would flip here, and the bar's own slot would go blank with it.
    assert.equal(run([8]).direction, 'up');
    assert.equal(run([43]).direction, 'up');
    assert.equal(run([44]).direction, 'down');
});

test('a reveal deep in the page re-hides on the plain threshold', () => {
    const revealed = run([1000, 990]);

    assert.deepEqual(revealed, { direction: 'up', anchorY: 990 });
    // Nothing to keep on screen down here: the reader is far past where the bar's slot was.
    assert.equal(run([998], revealed).direction, 'down');
});

const hidden: PageDirection = { direction: 'down', url: '/diary/list' };

test('a direction belongs to the page it was decided on', () => {
    assert.equal(renderedDirection(hidden, '/diary/list', true), 'down');
    // Inertia keeps the document across a navigation; the page arriving must not paint hidden.
    assert.equal(renderedDirection(hidden, '/dashboard', true), 'up');
});

test('a disabled caller has no direction', () => {
    assert.equal(renderedDirection(hidden, '/diary/list', false), 'up');
});
