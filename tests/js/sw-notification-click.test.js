import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { runInThisContext } from 'node:vm';

/**
 * A notification tap on the real handler: with no window the app opens at its scope root — never at
 * the destination — and is told where to go; with windows, the first page that takes the offer is
 * focused, and when none does the front window is shown and, except on Safari, navigated.
 */
const source = readFileSync(fileURLToPath(new URL('../../public/sw.js', import.meta.url)), 'utf8');
const SCOPE = 'https://sns.example/';

if (typeof navigator === 'undefined') {
    globalThis.navigator = {};
}

function boot(windows) {
    const handlers = {};
    const calls = { openWindow: [], messages: [], focused: [], navigated: [] };
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

/** A window; `receiver: true` is a page with the open handler, which ACKs on the port it was handed. */
const aWindow = (calls, name, { receiver = true, focus } = {}) => {
    const client = {
        postMessage: (data, ports = []) => {
            calls.messages.push([name, data]);
            if (receiver && ports[0]) {
                ports[0].postMessage({ type: 'ack' });
                ports[0].close();
            }
        },
        navigate: async (url) => calls.navigated.push([name, url]),
    };
    client.focus = focus ?? (async () => calls.focused.push(name) && client);

    return client;
};

test('no window: the app opens at its scope root, and the destination travels as a message', async () => {
    const { handlers, calls } = boot(() => []);
    await tap(handlers, { url: '/notifications' });
    assert.deepEqual(calls.openWindow, [SCOPE]);
    assert.deepEqual(calls.messages, [['opened', { type: 'open', url: '/notifications' }]]);
    assert.deepEqual(calls.navigated, []);
});

test('a window with a receiver: told and focused, nothing opened or navigated', async () => {
    const { handlers, calls } = boot((c) => [aWindow(c, 'w')]);
    await tap(handlers, { url: '/notifications' });
    assert.deepEqual(calls.openWindow, []);
    assert.deepEqual(calls.messages, [['w', { type: 'open', url: '/notifications' }]]);
    assert.deepEqual(calls.focused, ['w']);
    assert.deepEqual(calls.navigated, []);
});

test('a receiver-less window in front does not swallow the tap: the next page that takes it is focused', async () => {
    const { handlers, calls } = boot((c) => [aWindow(c, 'admin', { receiver: false }), aWindow(c, 'member')]);
    await tap(handlers, { url: '/notifications' });
    assert.deepEqual(calls.messages.map(([name]) => name), ['admin', 'member']);
    assert.deepEqual(calls.focused, ['member']);
    assert.deepEqual(calls.openWindow, []);
    assert.deepEqual(calls.navigated, []);
});

test('no page takes it: the front window is focused and navigated, never a second window opened', async () => {
    const { handlers, calls } = boot((c) => [aWindow(c, 'login', { receiver: false })]);
    await tap(handlers, { url: '/notifications' });
    assert.deepEqual(calls.focused, ['login']);
    assert.deepEqual(calls.navigated, [['login', '/notifications']]);
    assert.deepEqual(calls.openWindow, []);
});

test('on Safari the unclaimed front window is shown but never navigated', async () => {
    const IOS = 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148';
    Object.defineProperty(navigator, 'userAgent', { value: IOS, configurable: true });
    try {
        const { handlers, calls } = boot((c) => [aWindow(c, 'login', { receiver: false })]);
        await tap(handlers, { url: '/notifications' });
        assert.deepEqual(calls.focused, ['login']);
        assert.deepEqual(calls.navigated, []);
        assert.deepEqual(calls.openWindow, []);
    } finally {
        delete navigator.userAgent;
    }
});

test('a refused focus does not lose the tap', async () => {
    const refuse = async () => {
        throw new Error('not allowed');
    };
    let booted = boot((c) => [aWindow(c, 'w', { focus: refuse })]);
    await tap(booted.handlers, { url: '/notifications' });
    assert.deepEqual(booted.calls.messages, [['w', { type: 'open', url: '/notifications' }]]);

    booted = boot((c) => [aWindow(c, 'login', { receiver: false, focus: refuse })]);
    await tap(booted.handlers, { url: '/notifications' });
    assert.deepEqual(booted.calls.navigated, [['login', '/notifications']]);
});

test('the payload names the destination; without one it is the feed', async () => {
    let booted = boot(() => []);
    await tap(booted.handlers, { url: '/groups/3/talk' });
    assert.equal(booted.calls.messages[0][1].url, '/groups/3/talk');

    booted = boot(() => []);
    await tap(booted.handlers, undefined);
    assert.equal(booted.calls.messages[0][1].url, '/notifications');
});
