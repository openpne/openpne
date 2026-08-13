import assert from 'node:assert/strict';
import { test } from 'node:test';
import { nextReport, settleReport } from './mark-read.ts';

test('a newly rendered message is worth reporting', () => {
    assert.equal(nextReport(7, 3), 7);
});

test('nothing rendered means nothing to report', () => {
    assert.equal(nextReport(undefined, 0), null);
    assert.equal(nextReport(undefined, 9), null);
});

test('an acknowledged message is not reported twice', () => {
    assert.equal(nextReport(5, 5), null);
});

/**
 * Order is the (created_at, id) tuple and migrated ids are not monotonic in time, so a numerically
 * smaller id can be the newest rendered message. The client reports it and leaves direction to the
 * server's monotonic guard — suppressing on `<=` would silently strand such rows unread.
 */
test('a smaller id that is newest by tuple is still reported', () => {
    assert.equal(nextReport(2, 8), 2);
});

test('the first report is made from a standing start', () => {
    assert.equal(nextReport(1, 0), 1);
});

test('a 2xx acknowledges the position and clears the queue', () => {
    const { state, retry } = settleReport({ acked: 0, pending: 7 }, 7, true);
    assert.deepEqual(state, { acked: 7, pending: null });
    assert.equal(retry, false);
});

test('a success under a newer queued id keeps that id and asks to continue', () => {
    const { state, retry } = settleReport({ acked: 0, pending: 9 }, 7, true);
    assert.deepEqual(state, { acked: 7, pending: 9 });
    assert.equal(retry, true);
});

/** A network reject and a non-2xx settle the same way: unacknowledged, same id retryable. */
test('a failed report keeps the id claimable and asks for a retry', () => {
    const { state, retry } = settleReport({ acked: 3, pending: null }, 7, false);
    assert.deepEqual(state, { acked: 3, pending: 7 });
    assert.equal(retry, true);
});

test('a failure never overwrites a newer queued id', () => {
    const { state, retry } = settleReport({ acked: 3, pending: 9 }, 7, false);
    assert.deepEqual(state, { acked: 3, pending: 9 });
    assert.equal(retry, true);
});
