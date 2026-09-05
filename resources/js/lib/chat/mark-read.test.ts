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

/** Migrated ids are not monotonic in time, so the newest rendered message can hold a smaller id. */
test('a smaller id that is newest by tuple is still reported', () => {
    assert.equal(nextReport(2, 8), 2);
});

test('the first report is made from a standing start', () => {
    assert.equal(nextReport(1, 0), 1);
});

test('a 2xx acknowledges the position and clears the queue', () => {
    const { state, retry } = settleReport({ acked: 0, pending: 7 }, 7, 'ok');
    assert.deepEqual(state, { acked: 7, pending: null });
    assert.equal(retry, false);
});

test('a success under a newer queued id keeps that id and asks to continue', () => {
    const { state, retry } = settleReport({ acked: 0, pending: 9 }, 7, 'ok');
    assert.deepEqual(state, { acked: 7, pending: 9 });
    assert.equal(retry, true);
});

test('a retryable failure keeps the id claimable and asks for a retry', () => {
    const { state, retry } = settleReport({ acked: 3, pending: null }, 7, 'retryable');
    assert.deepEqual(state, { acked: 3, pending: 7 });
    assert.equal(retry, true);
});

test('a retryable failure never overwrites a newer queued id', () => {
    const { state, retry } = settleReport({ acked: 3, pending: 9 }, 7, 'retryable');
    assert.deepEqual(state, { acked: 3, pending: 9 });
    assert.equal(retry, true);
});

test('a terminal refusal drops the id without a retry', () => {
    const { state, retry } = settleReport({ acked: 3, pending: 7 }, 7, 'terminal');
    assert.deepEqual(state, { acked: 7, pending: null });
    assert.equal(retry, false);
});

test('a terminal refusal still continues for a newer queued id', () => {
    const { state, retry } = settleReport({ acked: 3, pending: 9 }, 7, 'terminal');
    assert.deepEqual(state, { acked: 7, pending: 9 });
    assert.equal(retry, true);
});
