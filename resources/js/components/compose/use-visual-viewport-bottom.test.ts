import assert from 'node:assert/strict';
import { test } from 'node:test';
import { computeViewportMetrics, observeViewportMetrics, type ViewportLike } from './use-visual-viewport-bottom.ts';

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

test('computeViewportMetrics: keyboard closed → bottom 0, band = full viewport', () => {
    assert.deepEqual(computeViewportMetrics({ innerHeight: 844, height: 844, offsetTop: 0 } as ViewportLike), {
        bottom: 0,
        height: 844,
        offsetTop: 0,
    });
});

test('computeViewportMetrics: keyboard open → covered height + shrunken band', () => {
    assert.deepEqual(computeViewportMetrics({ innerHeight: 844, height: 544, offsetTop: 0 } as ViewportLike), {
        bottom: 300,
        height: 544,
        offsetTop: 0,
    });
});

test('computeViewportMetrics: offsetTop is subtracted from bottom and exposed', () => {
    assert.deepEqual(computeViewportMetrics({ innerHeight: 844, height: 500, offsetTop: 44 } as ViewportLike), {
        bottom: 300,
        height: 500,
        offsetTop: 44,
    });
});

test('computeViewportMetrics: clamps negative bottom to 0', () => {
    assert.equal(computeViewportMetrics({ innerHeight: 844, height: 900, offsetTop: 0 } as ViewportLike).bottom, 0);
});

test('computeViewportMetrics: missing viewport → zero', () => {
    assert.deepEqual(computeViewportMetrics(null), { bottom: 0, height: 0, offsetTop: 0 });
    assert.deepEqual(computeViewportMetrics(undefined), { bottom: 0, height: 0, offsetTop: 0 });
});

test('observeViewportMetrics: reports the initial value synchronously', () => {
    const values: number[] = [];
    const cleanup = observeViewportMetrics(mockViewport({ innerHeight: 844, height: 544 }) as ViewportLike, (m) => values.push(m.bottom));
    assert.deepEqual(values, [300]);
    cleanup();
});

test('observeViewportMetrics: missing viewport reports zero once, cleanup is a no-op', () => {
    const values: number[] = [];
    const cleanup = observeViewportMetrics(null, (m) => values.push(m.bottom));
    assert.deepEqual(values, [0]);
    assert.doesNotThrow(cleanup);
});

test('observeViewportMetrics: resize updates the offset', async () => {
    const viewport = mockViewport({ innerHeight: 844, height: 844 });
    const values: number[] = [];
    const cleanup = observeViewportMetrics(viewport as ViewportLike, (m) => values.push(m.bottom));
    assert.deepEqual(values, [0]);

    viewport.height = 500; // keyboard opens
    viewport.emit('resize');
    await flushFrame();
    assert.equal(values.at(-1), 344);
    cleanup();
});

test('observeViewportMetrics: scroll updates the band (offsetTop shift)', async () => {
    const viewport = mockViewport({ innerHeight: 844, height: 500, offsetTop: 0 });
    const bands: { bottom: number; offsetTop: number }[] = [];
    const cleanup = observeViewportMetrics(viewport as ViewportLike, (m) => bands.push({ bottom: m.bottom, offsetTop: m.offsetTop }));
    assert.deepEqual(bands.at(-1), { bottom: 344, offsetTop: 0 });

    viewport.offsetTop = 44;
    viewport.emit('scroll');
    await flushFrame();
    assert.deepEqual(bands.at(-1), { bottom: 300, offsetTop: 44 });
    cleanup();
});

test('observeViewportMetrics: coalesces multiple events into one frame', async () => {
    const viewport = mockViewport({ innerHeight: 844, height: 844 });
    let calls = 0;
    const cleanup = observeViewportMetrics(viewport as ViewportLike, () => {
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

test('observeViewportMetrics: cleanup removes listeners and stops updates', async () => {
    const viewport = mockViewport({ innerHeight: 844, height: 844 });
    let calls = 0;
    const cleanup = observeViewportMetrics(viewport as ViewportLike, () => {
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
