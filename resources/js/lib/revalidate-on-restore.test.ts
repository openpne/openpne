import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createRestoreRevalidator } from './revalidate-on-restore.ts';

function harness() {
    let reloads = 0;
    const revalidator = createRestoreRevalidator(() => {
        reloads += 1;
    });

    return { revalidator, reloads: () => reloads };
}

test('an ordinary navigation reloads nothing', () => {
    const { revalidator, reloads } = harness();
    revalidator.handleArrival('navigate');

    revalidator.handleNavigate();

    assert.equal(reloads(), 0);
});

test('a restore reloads once its navigate has swapped the stored props in', () => {
    const { revalidator, reloads } = harness();

    revalidator.handlePopstate(true);
    assert.equal(reloads(), 0);

    revalidator.handleNavigate();
    assert.equal(reloads(), 1);

    // The navigation after it came from the server.
    revalidator.handleNavigate();
    assert.equal(reloads(), 1);
});

test('a hash-only popstate is not a restore', () => {
    const { revalidator, reloads } = harness();

    revalidator.handlePopstate(false);
    revalidator.handleNavigate();

    assert.equal(reloads(), 0);
});

test('rapid backs reload once per restore, not once per burst', () => {
    const { revalidator, reloads } = harness();

    // Both popstates land before either navigate does — the sequence history-restore.ts documents.
    revalidator.handlePopstate(true);
    revalidator.handlePopstate(true);
    revalidator.handleNavigate();
    revalidator.handleNavigate();

    assert.equal(reloads(), 2);
});

test('a bfcache return reloads directly: no navigate follows it', () => {
    const { revalidator, reloads } = harness();

    revalidator.handlePageshow(true);

    assert.equal(reloads(), 1);
});

test('an ordinary pageshow reloads nothing', () => {
    const { revalidator, reloads } = harness();

    revalidator.handlePageshow(false);

    assert.equal(reloads(), 0);
});

test('a back/forward document arrival is completed by the boot navigate', () => {
    const { revalidator, reloads } = harness();
    revalidator.handleArrival('back_forward');

    revalidator.handleNavigate();
    assert.equal(reloads(), 1);

    revalidator.handleNavigate();
    assert.equal(reloads(), 1);
});
