import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createRestoreQueue, createRestoreTracker } from './history-restore.ts';

const FEED = 'https://example.test/notifications';
const DASHBOARD = 'https://example.test/dashboard';

test('a page reached without a popstate is not a restore', () => {
    const tracker = createRestoreTracker();

    assert.equal(tracker.consume(FEED), false);
});

test('a popstate is a restore for the page it landed on, and reading it spends it', () => {
    const tracker = createRestoreTracker();
    tracker.handlePopstate(FEED);

    assert.equal(tracker.consume(FEED), true);
    // The page that asked has already reloaded; a second render must not reload again.
    assert.equal(tracker.consume(FEED), false);
});

test('a restore belongs to its own page and no other', () => {
    const tracker = createRestoreTracker();
    // Back onto a page that does not revalidate: nothing consumes the record.
    tracker.handlePopstate(DASHBOARD);

    // Whatever the member visits next came from the server, however stale the page before it was.
    assert.equal(tracker.consume(FEED), false);
});

test('a paged feed restores per page', () => {
    const tracker = createRestoreTracker();
    tracker.handlePopstate(`${FEED}?page=2`);

    assert.equal(tracker.consume(FEED), false);
    assert.equal(tracker.consume(`${FEED}?page=2`), true);
});

test('an ordinary navigate completes no restore', () => {
    const queue = createRestoreQueue();

    assert.equal(queue.handleNavigate(), false);
});

test("a restore is completed by its own navigate, and by nothing after it", () => {
    const queue = createRestoreQueue();
    queue.handlePopstate();

    assert.equal(queue.handleNavigate(), true);
    assert.equal(queue.handleNavigate(), false);
});

test('holding back completes every restore, not just the first', () => {
    const queue = createRestoreQueue();
    // Both popstates land before either navigate does — the sequence back-nav.ts documents.
    queue.handlePopstate();
    queue.handlePopstate();

    assert.equal(queue.handleNavigate(), true);
    // A flag would answer false here, and the second restore's stale props would be the last write.
    assert.equal(queue.handleNavigate(), true);
    assert.equal(queue.handleNavigate(), false);
});
