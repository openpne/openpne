import assert from 'node:assert/strict';
import { test } from 'node:test';
import { nextReport } from './mark-read.ts';

test('a newer rendered message is worth reporting', () => {
    assert.equal(nextReport(7, 3), 7);
});

test('nothing rendered means nothing to report', () => {
    assert.equal(nextReport(undefined, 0), null);
    assert.equal(nextReport(undefined, 9), null);
});

/** The client keeps its own forward-only rule so a poll burst does not re-send what it just sent. */
test('the same message is not reported twice', () => {
    assert.equal(nextReport(5, 5), null);
});

/**
 * Loading older history renders an older newest-id in no case, but a merge that drops the newest
 * message (a delete) can. Reporting it would ask the server to move the cursor back — which its own
 * guard refuses anyway, so the call would be pure waste.
 */
test('an older message is never reported', () => {
    assert.equal(nextReport(2, 8), null);
});

test('the first report is made from a standing start', () => {
    assert.equal(nextReport(1, 0), 1);
});
