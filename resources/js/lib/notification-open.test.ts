import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createNotificationOpen } from './notification-open.ts';

const message = (data: unknown, port?: { postMessage(data: unknown): void }) =>
    ({ data, ports: port ? [port] : [] }) as unknown as MessageEvent;

test('an offer is answered on its port', () => {
    const answers: unknown[] = [];
    const open = createNotificationOpen(() => assert.fail('an offer is not a destination'));
    open.handleMessage(message({ type: 'open-offer' }, { postMessage: (data) => answers.push(data) }));
    assert.deepEqual(answers, [{ type: 'ack' }]);
});

test('a destination that arrives before the router is ready is followed when it is', () => {
    const visited: string[] = [];
    const open = createNotificationOpen((url) => visited.push(url));
    open.handleMessage(message({ type: 'open', url: '/notifications' }));
    assert.deepEqual(visited, []);
    open.ready();
    assert.deepEqual(visited, ['/notifications']);
    open.ready();
    assert.deepEqual(visited, ['/notifications'], 'followed once');
});

test('a destination that arrives after ready is followed at once', () => {
    const visited: string[] = [];
    const open = createNotificationOpen((url) => visited.push(url));
    open.ready();
    open.handleMessage(message({ type: 'open', url: '/groups/3/talk' }));
    assert.deepEqual(visited, ['/groups/3/talk']);
});

test('anything else from the worker is not a destination', () => {
    const visited: string[] = [];
    const open = createNotificationOpen((url) => visited.push(url));
    open.ready();
    open.handleMessage(message({ type: 'refresh-unread' }));
    open.handleMessage(message({ type: 'open' }));
    open.handleMessage(message('open'));
    open.handleMessage(message(null));
    assert.deepEqual(visited, []);
});
