import assert from 'node:assert/strict';
import { test } from 'node:test';
import { backTarget, createBackTracker } from './back-nav.ts';

test('the initial load is not a step forward', () => {
    const tracker = createBackTracker();
    tracker.handleNavigate();

    assert.equal(tracker.getSnapshot(), 0);
    assert.equal(tracker.hasInAppHistory(), false);
});

test('each visit after that is a step', () => {
    const tracker = createBackTracker();
    tracker.handleNavigate();
    tracker.handleNavigate();

    assert.equal(tracker.getSnapshot(), 1);
    assert.equal(tracker.hasInAppHistory(), true);

    tracker.handleNavigate();
    assert.equal(tracker.getSnapshot(), 2);
});

test('a popstate steps back, and the navigate it causes is not counted again', () => {
    const tracker = createBackTracker();
    tracker.handleNavigate();
    tracker.handleNavigate();
    tracker.handleNavigate();

    tracker.handlePopstate();
    assert.equal(tracker.getSnapshot(), 1);
    tracker.handleNavigate();
    assert.equal(tracker.getSnapshot(), 1);

    // The pending pop is spent, so the next visit counts as usual.
    tracker.handleNavigate();
    assert.equal(tracker.getSnapshot(), 2);
});

test('rapid popstates each discount their own navigate', () => {
    // Holding/double-pressing back fires the popstates before their navigates. A flag (rather
    // than a counter) would absorb both pops and count the second navigate as a push — an
    // over-count that could offer a history control whose back leaves the app.
    const tracker = createBackTracker();
    tracker.handleNavigate();
    tracker.handleNavigate();
    tracker.handleNavigate();
    assert.equal(tracker.getSnapshot(), 2);

    tracker.handlePopstate();
    tracker.handlePopstate();
    assert.equal(tracker.getSnapshot(), 0);

    tracker.handleNavigate();
    tracker.handleNavigate();
    assert.equal(tracker.getSnapshot(), 0);
    assert.equal(tracker.hasInAppHistory(), false);
});

test('walking back to where the session started leaves no history', () => {
    const tracker = createBackTracker();
    tracker.handleNavigate();
    tracker.handleNavigate();

    tracker.handlePopstate();
    tracker.handleNavigate();

    assert.equal(tracker.hasInAppHistory(), false);
});

test('depth never goes negative', () => {
    const tracker = createBackTracker();
    tracker.handleNavigate();

    tracker.handlePopstate();
    tracker.handlePopstate();

    assert.equal(tracker.getSnapshot(), 0);
    assert.equal(tracker.hasInAppHistory(), false);
});

test('subscribers hear every event, until they unsubscribe', () => {
    const tracker = createBackTracker();
    let notifications = 0;
    const unsubscribe = tracker.subscribe(() => {
        notifications += 1;
    });

    tracker.handleNavigate();
    tracker.handleNavigate();
    tracker.handlePopstate();
    assert.equal(notifications, 3);

    unsubscribe();
    tracker.handleNavigate();
    assert.equal(notifications, 3);
});

test('back goes to the session history when there is any', () => {
    assert.deepEqual(backTarget(true), { type: 'history' });
    assert.deepEqual(backTarget(true, [{ href: '/community/1' }]), { type: 'history' });
});

test('back falls back to the nearest crumb, then to the dashboard', () => {
    assert.deepEqual(backTarget(false, [{ href: '/community/1' }, { href: '/community/1/topic' }]), {
        type: 'href',
        href: '/community/1/topic',
    });
    assert.deepEqual(backTarget(false, []), { type: 'href', href: '/dashboard' });
    assert.deepEqual(backTarget(false), { type: 'href', href: '/dashboard' });
});
