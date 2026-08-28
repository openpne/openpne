import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { runInThisContext } from 'node:vm';

/**
 * A notification tap on the real worker: with no window the app opens at its scope root — never at
 * the destination; every open window is offered the tap and the first to answer is focused and
 * handed the destination; a page that cannot answer yet is offered again; when none answers the
 * front window is shown and, except on WebKit, navigated.
 *
 * What this cannot see: whether the page's listener is in place when the offer arrives (the
 * DOMContentLoaded rule in lib/notification-open.ts). That is held by where the listener is
 * registered, not by a test here.
 */
const source = readFileSync(fileURLToPath(new URL('../../public/sw.js', import.meta.url)), 'utf8');
const SCOPE = 'https://sns.example/';
const QUICK = { offerMs: 30, deadlineMs: 150 };

if (typeof navigator === 'undefined') {
    globalThis.navigator = {};
}

/**
 * A window. `receiver: false` is a page with no handler; `answerFrom: n` a page that is still loading
 * for the first n-1 offers and answers from the nth.
 */
const aWindow = (calls, name, { receiver = true, answerFrom = 1, focus } = {}) => {
    let offers = 0;
    const client = {
        postMessage: (data, ports = []) => {
            calls.messages.push([name, data]);
            if (data.type === 'open-offer') {
                offers += 1;
                if (receiver && offers >= answerFrom && ports[0]) {
                    ports[0].postMessage({ type: 'ack' });
                    ports[0].close();
                }
            }
        },
        navigate: async (url) => calls.navigated.push([name, url]),
    };
    client.focus =
        focus ??
        (async () => {
            calls.focused.push(name);
            return client;
        });

    return client;
};

/**
 * Boots the worker against `windows` (what matchAll returns) and `opened` (what openWindow returns).
 * `unlistedFor`: how many matchAll calls after openWindow still leave the opened window out, as an
 * engine does until that page is ready.
 */
function boot(windows, opened = (calls) => aWindow(calls, 'opened'), { unlistedFor = 0 } = {}) {
    const handlers = {};
    const calls = { openWindow: [], messages: [], focused: [], navigated: [] };
    let openedWindow = null;
    let unlisted = unlistedFor;
    globalThis.self = {
        addEventListener: (type, handler) => {
            handlers[type] = handler;
        },
        skipWaiting: () => {},
        registration: { scope: SCOPE },
        clients: {
            claim: async () => {},
            matchAll: async () => {
                if (openedWindow && unlisted > 0) {
                    unlisted -= 1;
                    return [];
                }
                return openedWindow ? [openedWindow] : windows(calls);
            },
            openWindow: async (url) => {
                calls.openWindow.push(url);
                openedWindow = opened(calls);
                return openedWindow;
            },
        },
    };
    // Evaluated inside a function so each boot is fresh; the worker's own openInApp is handed back
    // for the cases that drive it with shorter timing than the handler's.
    const { openInApp } = runInThisContext(`(function () {\n${source}\n; return { openInApp }; })`, {
        filename: 'public/sw.js',
    })();

    return { handlers, calls, openInApp };
}

const tap = async (handlers, data) => {
    const pending = [];
    handlers.notificationclick({
        notification: { close() {}, data },
        waitUntil: (promise) => pending.push(promise),
    });
    await Promise.all(pending);
};

const sent = (calls, type) => calls.messages.filter(([, data]) => data.type === type).map(([name, data]) => [name, data.url]);

test('no window: the app opens at its scope root, and the destination goes to the page that opened', async () => {
    const { handlers, calls } = boot(() => []);
    await tap(handlers, { url: '/notifications' });
    assert.deepEqual(calls.openWindow, [SCOPE]);
    assert.deepEqual(sent(calls, 'open'), [['opened', '/notifications']]);
    assert.deepEqual(calls.navigated, []);
});

test('a window with a receiver: focused and handed the destination, nothing opened or navigated', async () => {
    const { handlers, calls } = boot((c) => [aWindow(c, 'w')]);
    await tap(handlers, { url: '/notifications' });
    assert.deepEqual(calls.openWindow, []);
    assert.deepEqual(calls.focused, ['w']);
    assert.deepEqual(sent(calls, 'open'), [['w', '/notifications']]);
    assert.deepEqual(calls.navigated, []);
});

test('a receiver-less window in front does not swallow the tap: all are offered, the first to answer is chosen', async () => {
    const { handlers, calls } = boot((c) => [aWindow(c, 'admin', { receiver: false }), aWindow(c, 'member')]);
    await tap(handlers, { url: '/notifications' });
    assert.deepEqual(sent(calls, 'open-offer').map(([name]) => name), ['admin', 'member']);
    assert.deepEqual(calls.focused, ['member']);
    assert.deepEqual(sent(calls, 'open'), [['member', '/notifications']]);
    assert.deepEqual(calls.openWindow, []);
    assert.deepEqual(calls.navigated, []);
});

test('a page still loading is offered again until it answers', async () => {
    const { calls, openInApp } = boot(() => [], (c) => aWindow(c, 'opened', { answerFrom: 3 }));
    await openInApp('/notifications', QUICK);
    assert.equal(sent(calls, 'open-offer').length, 3);
    assert.deepEqual(calls.focused, ['opened']);
    assert.deepEqual(sent(calls, 'open'), [['opened', '/notifications']]);
    assert.deepEqual(calls.navigated, []);
});

test('an opened window matchAll does not list yet is still offered until it answers', async () => {
    const { calls, openInApp } = boot(() => [], (c) => aWindow(c, 'opened', { answerFrom: 3 }), { unlistedFor: 3 });
    await openInApp('/notifications', QUICK);
    assert.equal(sent(calls, 'open-offer').length, 3);
    assert.deepEqual(sent(calls, 'open'), [['opened', '/notifications']]);
    assert.deepEqual(calls.openWindow.length, 1, 'opened once');
    assert.deepEqual(calls.navigated, []);
});

test('no page answers by the deadline: the front window is shown and navigated, never a second window opened', async () => {
    const login = { current: null };
    const { calls, openInApp } = boot((c) => [(login.current ??= aWindow(c, 'login', { receiver: false }))]);
    await openInApp('/notifications', QUICK);
    assert.ok(sent(calls, 'open-offer').length >= 2, 'offered more than once');
    assert.deepEqual(calls.focused, ['login']);
    assert.deepEqual(calls.navigated, [['login', '/notifications']]);
    assert.deepEqual(sent(calls, 'open'), []);
    assert.deepEqual(calls.openWindow, []);
});

test('on WebKit the unclaimed front window is shown but never navigated', async () => {
    const IOS = 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148';
    Object.defineProperty(navigator, 'userAgent', { value: IOS, configurable: true });
    try {
        const login = { current: null };
        const { calls, openInApp } = boot((c) => [(login.current ??= aWindow(c, 'login', { receiver: false }))]);
        await openInApp('/notifications', QUICK);
        assert.deepEqual(calls.focused, ['login']);
        assert.deepEqual(calls.navigated, []);
        assert.deepEqual(calls.openWindow, []);
    } finally {
        delete navigator.userAgent;
    }
});

test('a refused focus does not lose the destination', async () => {
    const refuse = async () => {
        throw new Error('not allowed');
    };
    const { handlers, calls } = boot((c) => [aWindow(c, 'w', { focus: refuse })]);
    await tap(handlers, { url: '/notifications' });
    assert.deepEqual(sent(calls, 'open'), [['w', '/notifications']]);
});

test('the payload names the destination; without one it is the feed', async () => {
    let booted = boot(() => []);
    await tap(booted.handlers, { url: '/groups/3/talk' });
    assert.deepEqual(sent(booted.calls, 'open'), [['opened', '/groups/3/talk']]);

    booted = boot(() => []);
    await tap(booted.handlers, undefined);
    assert.deepEqual(sent(booted.calls, 'open'), [['opened', '/notifications']]);
});
