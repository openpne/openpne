import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createRestoreTracker } from './history-restore.ts';

test('a page reached by an ordinary visit is not a restore', () => {
    const tracker = createRestoreTracker();
    tracker.handleVisitStart();

    assert.equal(tracker.consume(), false);
});

test('a popstate is a restore, and reading it spends it', () => {
    const tracker = createRestoreTracker();
    tracker.handlePopstate();

    assert.equal(tracker.consume(), true);
    // The page that asked has already reloaded; a second render must not reload again.
    assert.equal(tracker.consume(), false);
});

test('a restore no page asked about is dropped by the next visit', () => {
    const tracker = createRestoreTracker();
    // Back onto a page that does not revalidate: nothing consumes the record.
    tracker.handlePopstate();

    tracker.handleVisitStart();

    // The page this visit lands on is the server's answer, however stale the one before it was.
    assert.equal(tracker.consume(), false);
});
