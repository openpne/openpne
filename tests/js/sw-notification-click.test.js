import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { runInThisContext } from 'node:vm';

/**
 * A notification tap on the real handler: the worker focuses a window or opens the app at its scope
 * root — never at the destination — and names the destination in a message for the page to follow.
 */
const source = readFileSync(fileURLToPath(new URL('../../public/sw.js', import.meta.url)), 'utf8');
const SCOPE = 'https://sns.example/';

if (typeof navigator === 'undefined') {
    globalThis.navigator = {};
}

function boot(windows) {
    const handlers = {};
    const calls = { openWindow: [], messages: [] };
    const opened = { postMessage: (data) => calls.messages.push(['opened', data]) };
    globalThis.self = {
        addEventListener: (type, handler) => {
            handlers[type] = handler;
        },
        skipWaiting: () => {},
        registration: { scope: SCOPE },
        clients: {
            claim: async () => {},
            matchAll: async () => windows(calls),
            openWindow: async (url) => {
                calls.openWindow.push(url);
                return opened;
            },
        },
    };
    runInThisContext(source, { filename: 'public/sw.js' });

    return { handlers, calls };
}

const tap = async (handlers, data) => {
    const pending = [];
    handlers.notificationclick({
        notification: { close() {}, data },
        waitUntil: (promise) => pending.push(promise),
    });
    await Promise.all(pending);
};

const aWindow = (calls, name, focus) => {
    const client = {
        navigate: () => assert.fail('navigate() is a no-op on iOS and must not be used'),
        postMessage: (data) => calls.messages.push([name, data]),
    };
    client.focus = focus ?? (async () => client);

    return client;
};

test('no window: the app opens at its scope root, and the destination travels as a message', async () => {
    const { handlers, calls } = boot(() => []);
    await tap(handlers, { url: '/notifications' });
    assert.deepEqual(calls.openWindow, [SCOPE]);
    assert.deepEqual(calls.messages, [['opened', { type: 'open', url: '/notifications' }]]);
});

test('a window: it is focused and told, nothing is opened', async () => {
    const { handlers, calls } = boot((c) => [aWindow(c, 'w')]);
    await tap(handlers, { url: '/notifications' });
    assert.deepEqual(calls.openWindow, []);
    assert.deepEqual(calls.messages, [['w', { type: 'open', url: '/notifications' }]]);
});

test('a window that refuses focus is still told', async () => {
    const { handlers, calls } = boot((c) => [aWindow(c, 'w', async () => { throw new Error('not allowed'); })]);
    await tap(handlers, { url: '/notifications' });
    assert.deepEqual(calls.openWindow, []);
    assert.deepEqual(calls.messages, [['w', { type: 'open', url: '/notifications' }]]);
});

test('the payload names the destination; without one it is the feed', async () => {
    let booted = boot(() => []);
    await tap(booted.handlers, { url: '/groups/3/talk' });
    assert.equal(booted.calls.messages[0][1].url, '/groups/3/talk');

    booted = boot(() => []);
    await tap(booted.handlers, undefined);
    assert.equal(booted.calls.messages[0][1].url, '/notifications');
});
