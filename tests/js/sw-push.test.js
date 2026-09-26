import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { runInThisContext } from 'node:vm';

const source = readFileSync(fileURLToPath(new URL('../../public/sw.js', import.meta.url)), 'utf8');

if (typeof navigator === 'undefined') {
    globalThis.navigator = {};
}

/** The port is closed either way, or node waits on it. */
const aClient = (calls, name, { visible = true, acks = true } = {}) => ({
    visibilityState: visible ? 'visible' : 'hidden',
    postMessage: (data, ports = []) => {
        calls.messages.push([name, data]);
        const port = ports[0];
        if (port && acks) {
            port.postMessage({ type: 'ack' });
        }
        port?.close();
    },
});

function boot(clients) {
    const handlers = {};
    const calls = { messages: [], notifications: [], badges: [] };
    globalThis.self = {
        addEventListener: (type, handler) => {
            handlers[type] = handler;
        },
        skipWaiting: () => {},
        registration: {
            scope: 'https://sns.example/',
            showNotification: async (title, options) => {
                calls.notifications.push([title, options]);
            },
        },
        clients: {
            claim: async () => {},
            matchAll: async () => clients(calls),
        },
    };
    Object.defineProperty(navigator, 'setAppBadge', {
        value: async (count) => {
            calls.badges.push(count);
        },
        configurable: true,
    });
    runInThisContext(`(function () {\n${source}\n})`, { filename: 'public/sw.js' })();

    return { handlers, calls };
}

const push = async (handlers, payload) => {
    const pending = [];
    handlers.push({
        data:
            payload === undefined
                ? null
                : {
                      json: () => {
                          if (typeof payload === 'string') {
                              throw new SyntaxError('not json');
                          }

                          return payload;
                      },
                  },
        waitUntil: (promise) => pending.push(promise),
    });
    await Promise.all(pending);
};

test('a push becomes a notification carrying the payload, under the shared tag unless it names one', async () => {
    const { handlers, calls } = boot(() => []);
    const data = { title: 'New comment', body: 'Rin replied', icon: '/icon.png', url: '/diary/1' };
    await push(handlers, data);
    await push(handlers, { ...data, tag: 'talk-7' });
    assert.deepEqual(calls.notifications, [
        ['New comment', { body: 'Rin replied', icon: '/icon.png', tag: 'openpne-notifications', data }],
        ['New comment', { body: 'Rin replied', icon: '/icon.png', tag: 'talk-7', data: { ...data, tag: 'talk-7' } }],
    ]);
});

test('a payload that is missing or unreadable still shows a notification', async () => {
    const { handlers, calls } = boot(() => []);
    await push(handlers, undefined);
    await push(handlers, 'not json');
    assert.deepEqual(
        calls.notifications.map(([title]) => title),
        ['OpenPNE', 'OpenPNE'],
    );
    assert.deepEqual(calls.badges, []);
});

test('with no visible tab the badge is written from the payload; a payload without a count leaves it', async () => {
    const { handlers, calls } = boot((c) => [aClient(c, 'background', { visible: false })]);
    await push(handlers, { title: 'x', unreadCount: 3 });
    await push(handlers, { title: 'x' });
    await push(handlers, { title: 'x', unreadCount: '4' });
    assert.deepEqual(calls.badges, [3]);
    assert.deepEqual(calls.messages, [], 'a hidden tab is not asked');
});

test('a visible tab that takes the refresh owns the badge: the payload count is not written', async () => {
    const { handlers, calls } = boot((c) => [aClient(c, 'background', { visible: false }), aClient(c, 'front')]);
    await push(handlers, { title: 'x', unreadCount: 3 });
    assert.deepEqual(calls.messages, [['front', { type: 'refresh-unread' }]]);
    assert.deepEqual(calls.badges, []);
});

test('a visible tab with no handler never answers, so the badge falls back to the payload', async () => {
    const { handlers, calls } = boot((c) => [aClient(c, 'login', { acks: false })]);
    await push(handlers, { title: 'x', unreadCount: 3 });
    assert.deepEqual(calls.messages, [['login', { type: 'refresh-unread' }]]);
    assert.deepEqual(calls.badges, [3]);
});

test('without MessageChannel no tab is asked and the payload is written', async () => {
    const Channel = globalThis.MessageChannel;
    globalThis.MessageChannel = undefined;
    try {
        const { handlers, calls } = boot((c) => [aClient(c, 'front')]);
        await push(handlers, { title: 'x', unreadCount: 4 });
        assert.deepEqual(calls.messages, []);
        assert.deepEqual(calls.badges, [4]);
    } finally {
        globalThis.MessageChannel = Channel;
    }
});
