import { act, cleanup, renderHook } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { useMarkRead } from './use-mark-read';
import { answer, visibility, wire } from '@/lib/test-fetch';
import { UNREAD_REFRESH_EVENT } from '@/lib/unread-refresh';

vi.mock('@/lib/csrf', () => ({ xsrfHeader: () => ({ 'X-XSRF-TOKEN': 'token' }) }));

const DEBOUNCE_MS = 700;
const RETRY_MS = 5_000;


const reported = (init: RequestInit) => JSON.parse(init.body as string) as { messageId: number };

function refreshes() {
    const spy = vi.fn();
    window.addEventListener(UNREAD_REFRESH_EVENT, spy);

    return spy;
}

const advance = (ms: number) =>
    act(() => {
        vi.advanceTimersByTime(ms);
    });

interface Props {
    id: number | undefined;
    active: boolean;
}

const mount = (id: number | undefined, active = true) =>
    renderHook((props: Props) => useMarkRead('/talk/read', props.id, props.active), { initialProps: { id, active } });

afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.unstubAllGlobals();
    Reflect.deleteProperty(document, 'visibilityState');
});

test('the newest rendered message is reported once the burst settles, and an accepted report asks the badge to refresh', async () => {
    vi.useFakeTimers();
    const net = wire();
    const refreshed = refreshes();
    mount(7);

    await advance(DEBOUNCE_MS - 1);
    expect(net.fetch).not.toHaveBeenCalled();
    await advance(1);
    expect(net.url(0)).toBe('/talk/read');
    expect(net.init(0)).toMatchObject({
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-XSRF-TOKEN': 'token', 'Content-Type': 'application/json', Accept: 'application/json' },
    });
    expect(reported(net.init(0))).toEqual({ messageId: 7 });
    expect(refreshed).not.toHaveBeenCalled();

    await net.settle(0, answer(null, 204));
    expect(refreshed).toHaveBeenCalledTimes(1);
});

test('nothing is reported with nothing rendered, while inactive, while hidden, or for an id already acknowledged', async () => {
    vi.useFakeTimers();
    const net = wire();
    const { rerender } = mount(undefined);
    await advance(DEBOUNCE_MS);
    rerender({ id: 7, active: false });
    await advance(DEBOUNCE_MS);
    expect(net.fetch).not.toHaveBeenCalled();

    visibility('hidden');
    rerender({ id: 7, active: true });
    await advance(DEBOUNCE_MS);
    expect(net.fetch).not.toHaveBeenCalled();

    visibility('visible');
    act(() => {
        document.dispatchEvent(new Event('visibilitychange'));
    });
    expect(net.fetch).toHaveBeenCalledTimes(1);
    await net.settle(0, answer(null, 204));

    rerender({ id: 7, active: false });
    rerender({ id: 7, active: true });
    await advance(DEBOUNCE_MS);
    expect(net.fetch).toHaveBeenCalledTimes(1);

    rerender({ id: 8, active: true });
    await advance(DEBOUNCE_MS);
    expect(net.fetch).toHaveBeenCalledTimes(2);
    expect(reported(net.init(1))).toEqual({ messageId: 8 });
});

test('a 5xx or a dropped request retries the same id; a terminal 4xx settles it without a refresh', async () => {
    vi.useFakeTimers();
    const net = wire();
    const refreshed = refreshes();
    const { rerender } = mount(7);

    await advance(DEBOUNCE_MS);
    await net.settle(0, answer(null, 503));
    await advance(RETRY_MS - 1);
    expect(net.fetch).toHaveBeenCalledTimes(1);
    await advance(1);
    expect(net.fetch).toHaveBeenCalledTimes(2);
    expect(reported(net.init(1))).toEqual({ messageId: 7 });

    await net.drop(1);
    await advance(RETRY_MS);
    expect(net.fetch).toHaveBeenCalledTimes(3);

    await net.settle(2, answer(null, 404));
    await advance(RETRY_MS);
    expect(net.fetch).toHaveBeenCalledTimes(3);
    expect(refreshed).not.toHaveBeenCalled();

    // Settled as unwinnable: only a different newest message is worth reporting.
    rerender({ id: 7, active: false });
    rerender({ id: 7, active: true });
    await advance(DEBOUNCE_MS);
    expect(net.fetch).toHaveBeenCalledTimes(3);
    rerender({ id: 9, active: true });
    await advance(DEBOUNCE_MS);
    expect(reported(net.init(3))).toEqual({ messageId: 9 });
});

test('a newer message rendered while a report is out is reported after it, not alongside it', async () => {
    vi.useFakeTimers();
    const net = wire();
    const { rerender } = mount(7);

    await advance(DEBOUNCE_MS);
    rerender({ id: 8, active: true });
    await advance(DEBOUNCE_MS);
    expect(net.fetch).toHaveBeenCalledTimes(1);

    await net.settle(0, answer(null, 200));
    await advance(DEBOUNCE_MS);
    expect(net.fetch).toHaveBeenCalledTimes(2);
    expect(reported(net.init(1))).toEqual({ messageId: 8 });
});

test('unmounting cancels a report still waiting', async () => {
    vi.useFakeTimers();
    const net = wire();
    const { unmount } = mount(7);

    unmount();
    await advance(DEBOUNCE_MS);

    expect(net.fetch).not.toHaveBeenCalled();
});
