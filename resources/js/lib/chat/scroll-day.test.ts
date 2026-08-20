import assert from 'node:assert/strict';
import { test } from 'node:test';
import { rowAtLine } from './scroll-day.ts';

/** Twelve rows of forty pixels, starting at zero: feet at 40, 80, 120 … 480. */
const feet = Array.from({ length: 12 }, (_, index) => (index + 1) * 40);
const footOf = (index: number) => feet[index] ?? 0;

test('the row that owns the line is the first whose foot is below it', () => {
    assert.equal(rowAtLine(feet.length, footOf, 0), 0);
    assert.equal(rowAtLine(feet.length, footOf, 100), 2);
    assert.equal(rowAtLine(feet.length, footOf, 479), 11);
});

test('a row whose foot rests exactly on the line has already passed it', () => {
    // The line is where the reading area begins; a row ending on it is above, not at, the top.
    assert.equal(rowAtLine(feet.length, footOf, 40), 1);
    assert.equal(rowAtLine(feet.length, footOf, 80), 2);
});

test('nothing owns the line once every row is above it', () => {
    assert.equal(rowAtLine(feet.length, footOf, 480), null);
    assert.equal(rowAtLine(feet.length, footOf, 10_000), null);
});

test('an empty list owns nothing', () => {
    assert.equal(rowAtLine(0, () => 0, 0), null);
});

/**
 * The contract the search rests on, stated as a test rather than left in a comment: rows are in DOM
 * order, which is chronological, which is top to bottom. Break that and the answer is silently wrong
 * rather than slow, so this walks every line a linear scan and the search could disagree on.
 */
test('over a monotonic list it agrees with a linear scan everywhere', () => {
    const uneven = [12, 30, 31, 90, 91, 200, 340, 341, 342, 500];
    const linear = (line: number) => {
        const found = uneven.findIndex((foot) => foot > line);

        return found === -1 ? null : found;
    };

    for (let line = -5; line <= 505; line += 1) {
        assert.equal(rowAtLine(uneven.length, (index) => uneven[index] ?? 0, line), linear(line), `line ${line}`);
    }
});
