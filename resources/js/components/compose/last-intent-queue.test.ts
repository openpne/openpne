import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createLastIntentQueue } from './last-intent-queue.ts';

/** Let every already-settled promise continuation run. */
const flush = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 0));

/** A `send` whose promises are settled by hand, so interleavings are exact rather than timed. */
function deferredSender() {
    const sent: string[] = [];
    const settlers: { resolve: () => void; reject: () => void }[] = [];

    const send = (value: string): Promise<void> => {
        sent.push(value);
        return new Promise<void>((resolve, reject) => settlers.push({ resolve, reject: () => reject(new Error('nope')) }));
    };

    return {
        sent,
        send,
        /** Settle the oldest in-flight send and let the queue advance. */
        settle: async (outcome: 'resolve' | 'reject' = 'resolve') => {
            settlers.shift()?.[outcome]();
            await flush();
        },
    };
}

test('sends the first value immediately', () => {
    const { sent, send } = deferredSender();
    const push = createLastIntentQueue(send);

    push('rich');

    assert.deepEqual(sent, ['rich']);
});

test('coalesces to the last intent while a send is in flight', async () => {
    const { sent, send, settle } = deferredSender();
    const push = createLastIntentQueue(send);

    push('rich');
    push('markdown');
    push('plain');
    assert.deepEqual(sent, ['rich'], 'only the in-flight send has gone out');

    await settle();
    assert.deepEqual(sent, ['rich', 'plain'], 'the intermediate value is skipped, the final one wins');

    await settle();
    assert.deepEqual(sent, ['rich', 'plain'], 'and the queue then goes quiet');
});

test('a call after the queue drained starts a fresh send', async () => {
    const { sent, send, settle } = deferredSender();
    const push = createLastIntentQueue(send);

    push('rich');
    await settle();
    push('plain');
    await flush();

    assert.deepEqual(sent, ['rich', 'plain']);
});

test('a rejected send is dropped but never strands the value behind it', async () => {
    const { sent, send, settle } = deferredSender();
    const push = createLastIntentQueue(send);

    push('rich');
    push('plain');
    await settle('reject');
    assert.deepEqual(sent, ['rich', 'plain'], 'the queued choice still goes out');

    // ...and the queue is not wedged: a later choice is sent once that one settles too.
    await settle();
    push('markdown');
    await flush();
    assert.deepEqual(sent, ['rich', 'plain', 'markdown']);
});
