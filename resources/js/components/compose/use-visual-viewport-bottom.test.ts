import assert from 'node:assert/strict';
import { test } from 'node:test';
import { computeViewportBottom, observeViewportBottom, type ViewportLike } from './use-visual-viewport-bottom.ts';

/**
 * A driveable stand-in for window.visualViewport: mutable dimensions plus captured listeners the test
 * fires by hand. No browser, no rAF of its own — the module's rAF falls back to a timer under
 * `node --test`, so a short wait flushes a coalesced frame.
 */
function mockViewport(init: { innerHeight: number; height: number; offsetTop?: number }) {
    const listeners = new Map<string, Set<() => void>>();
    return {
        innerHeight: init.innerHeight,
        height: init.height,
        offsetTop: init.offsetTop ?? 0,
        addEventListener(type: string, listener: () => void) {
            (listeners.get(type) ?? listeners.set(type, new Set()).get(type)!).add(listener);
        },
        removeEventListener(type: string, listener: () => void) {
            listeners.get(type)?.delete(listener);
        },
        emit(type: string) {
            for (const listener of listeners.get(type) ?? []) {
                listener();
            }
        },
        listenerCount(type: string) {
            return listeners.get(type)?.size ?? 0;
        },
    };
}

const flushFrame = () => new Promise((resolve) => setTimeout(resolve, 40));

test('computeViewportBottom: keyboard closed → 0', () => {
    assert.equal(computeViewportBottom({ innerHeight: 844, height: 844, offsetTop: 0 } as ViewportLike), 0);
});

test('computeViewportBottom: keyboard open → covered height', () => {
    assert.equal(computeViewportBottom({ innerHeight: 844, height: 544, offsetTop: 0 } as ViewportLike), 300);
});

test('computeViewportBottom: offsetTop is subtracted', () => {
    assert.equal(computeViewportBottom({ innerHeight: 844, height: 500, offsetTop: 44 } as ViewportLike), 300);
});

test('computeViewportBottom: clamps negative to 0', () => {
    assert.equal(computeViewportBottom({ innerHeight: 844, height: 900, offsetTop: 0 } as ViewportLike), 0);
});

test('computeViewportBottom: missing viewport → 0', () => {
    assert.equal(computeViewportBottom(null), 0);
    assert.equal(computeViewportBottom(undefined), 0);
});

test('observeViewportBottom: reports the initial value synchronously', () => {
    const values: number[] = [];
    const cleanup = observeViewportBottom(mockViewport({ innerHeight: 844, height: 544 }) as ViewportLike, (b) => values.push(b));
    assert.deepEqual(values, [300]);
    cleanup();
});

test('observeViewportBottom: missing viewport reports 0 once, cleanup is a no-op', () => {
    const values: number[] = [];
    const cleanup = observeViewportBottom(null, (b) => values.push(b));
    assert.deepEqual(values, [0]);
    assert.doesNotThrow(cleanup);
});

test('observeViewportBottom: resize updates the offset', async () => {
    const viewport = mockViewport({ innerHeight: 844, height: 844 });
    const values: number[] = [];
    const cleanup = observeViewportBottom(viewport as ViewportLike, (b) => values.push(b));
    assert.deepEqual(values, [0]);

    viewport.height = 500; // keyboard opens
    viewport.emit('resize');
    await flushFrame();
    assert.equal(values.at(-1), 344);
    cleanup();
});

test('observeViewportBottom: scroll updates the offset (offsetTop shift)', async () => {
    const viewport = mockViewport({ innerHeight: 844, height: 500, offsetTop: 0 });
    const values: number[] = [];
    const cleanup = observeViewportBottom(viewport as ViewportLike, (b) => values.push(b));
    assert.equal(values.at(-1), 344);

    viewport.offsetTop = 44;
    viewport.emit('scroll');
    await flushFrame();
    assert.equal(values.at(-1), 300);
    cleanup();
});

test('observeViewportBottom: coalesces multiple events into one frame', async () => {
    const viewport = mockViewport({ innerHeight: 844, height: 844 });
    let calls = 0;
    const cleanup = observeViewportBottom(viewport as ViewportLike, () => {
        calls += 1;
    });
    assert.equal(calls, 1); // initial

    viewport.height = 700;
    viewport.emit('resize');
    viewport.emit('scroll');
    viewport.emit('resize');
    await flushFrame();
    assert.equal(calls, 2); // three events, one coalesced update
    cleanup();
});

test('observeViewportBottom: cleanup removes listeners and stops updates', async () => {
    const viewport = mockViewport({ innerHeight: 844, height: 844 });
    let calls = 0;
    const cleanup = observeViewportBottom(viewport as ViewportLike, () => {
        calls += 1;
    });
    cleanup();
    assert.equal(viewport.listenerCount('resize'), 0);
    assert.equal(viewport.listenerCount('scroll'), 0);

    viewport.height = 500;
    viewport.emit('resize'); // no-op: listener already removed
    await flushFrame();
    assert.equal(calls, 1); // only the initial call
});
