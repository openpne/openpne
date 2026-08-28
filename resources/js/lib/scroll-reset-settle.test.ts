import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createResetSettler, expectingReset } from './scroll-reset-settle.ts';

test('the arrival completing an expected reset is held, and spends the record', () => {
    const settler = createResetSettler();

    settler.expect();
    assert.equal(settler.arrive(), true);
    assert.equal(settler.arrive(), false);
});

test('an arrival nobody expected is left where it is', () => {
    assert.equal(createResetSettler().arrive(), false);
});

test('a record the visit finished without spending is not left for a later arrival', () => {
    // The shape of a reset Inertia fires no navigate for (a same-URL visit replaces its entry): the
    // visit still finishes, and the record must not survive to be spent by a history restore.
    const settler = createResetSettler();

    settler.expect();
    settler.finish();
    assert.equal(settler.arrive(), false);
});

test('the callback reports only the pages it hands to Inertia to reset', () => {
    let expected = 0;
    const options = expectingReset({ preserveScroll: (page) => page.component === 'kept' }, { expect: () => expected++ });

    assert.equal(options.preserveScroll?.({ component: 'kept' }), true);
    assert.equal(expected, 0);
    assert.equal(options.preserveScroll?.({ component: 'reset' }), false);
    assert.equal(expected, 1);
});

test('a visit that keeps its scroll passes through untouched', () => {
    const options = {};

    assert.equal(
        expectingReset(options, {
            expect: () => assert.fail('nothing to expect'),
        }),
        options,
    );
});
