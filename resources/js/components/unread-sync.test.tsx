import { act, cleanup, render } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { UnreadSync } from './unread-sync';
import { answer, visibility, wire } from '@/lib/test-fetch';
import { UNREAD_REFRESH_EVENT } from '@/lib/unread-refresh';
import type { UnreadCounts } from '@/types';

const inertia = vi.hoisted(() => ({ page: { props: {} as Record<string, unknown> }, replaceProp: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ usePage: () => inertia.page, router: { replaceProp: inertia.replaceProp } }));

const pushLib = vi.hoisted(() => ({
    setAppBadge: vi.fn(),
    clearAppBadge: vi.fn(),
    reconcileSubscription: vi.fn(() => Promise.resolve()),
    resumeRegistration: vi.fn(),
}));
vi.mock('@/lib/push', () => pushLib);

const INTERVAL_MS = 60_000;

const counts = (notifications: number): UnreadCounts => ({ friendRequests: 0, unreadMessages: 0, notifications, groupTalks: 0 });

function arrive({
    unread = counts(0),
    push = null,
    memberId = 1,
}: {
    unread?: UnreadCounts | null;
    push?: { vapidPublicKey: string } | null;
    memberId?: number | null;
} = {}) {
    inertia.page.props = { unread, push, auth: { user: memberId === null ? null : { id: memberId } } };
}

const advance = (ms: number) =>
    act(() => {
        vi.advanceTimersByTime(ms);
    });

const ring = () =>
    act(() => {
        window.dispatchEvent(new CustomEvent(UNREAD_REFRESH_EVENT));
    });

afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.clearAllMocks();
    Reflect.deleteProperty(document, 'visibilityState');
    Reflect.deleteProperty(navigator, 'serviceWorker');
});

test('the badge follows the shared count while signed in, and is left alone for a guest', () => {
    arrive({ unread: counts(5) });
    const { rerender } = render(<UnreadSync />);
    expect(pushLib.setAppBadge).toHaveBeenCalledWith(5);

    arrive({ unread: counts(0) });
    rerender(<UnreadSync />);
    expect(pushLib.clearAppBadge).toHaveBeenCalledTimes(1);

    cleanup();
    vi.clearAllMocks();
    arrive({ unread: null, memberId: null });
    render(<UnreadSync />);
    expect(pushLib.setAppBadge).not.toHaveBeenCalled();
    expect(pushLib.clearAppBadge).not.toHaveBeenCalled();
});

test('a push-configured page resumes registration and reconciles the subscription for the member', () => {
    arrive({ push: { vapidPublicKey: 'k' }, memberId: 42 });
    render(<UnreadSync />);
    expect(pushLib.resumeRegistration).toHaveBeenCalledTimes(1);
    expect(pushLib.reconcileSubscription).toHaveBeenCalledWith(42);

    cleanup();
    vi.clearAllMocks();
    arrive({ push: null });
    render(<UnreadSync />);
    expect(pushLib.resumeRegistration).not.toHaveBeenCalled();
    expect(pushLib.reconcileSubscription).not.toHaveBeenCalled();
});

test('every minute on a visible tab the counts are re-read into the shared props and the badge', async () => {
    vi.useFakeTimers();
    const net = wire();
    arrive({ unread: counts(1) });
    render(<UnreadSync />);

    await advance(INTERVAL_MS - 1);
    expect(net.fetch).not.toHaveBeenCalled();
    await advance(1);
    expect(net.url(0)).toBe('/unread-counts');
    expect(net.init(0)).toMatchObject({ credentials: 'same-origin', headers: { Accept: 'application/json' } });

    await net.settle(0, answer({ unread: counts(7), talkNavRooms: null }));
    expect(inertia.replaceProp).toHaveBeenCalledWith('unread', counts(7));
    expect(inertia.replaceProp).toHaveBeenCalledWith('talkNavRooms', null);
    expect(pushLib.setAppBadge).toHaveBeenLastCalledWith(7);

    await advance(INTERVAL_MS);
    expect(net.fetch).toHaveBeenCalledTimes(2);
});

test('a hidden tab is not refreshed; coming back to it is, and so is a page ringing the bell', async () => {
    vi.useFakeTimers();
    const net = wire();
    visibility('hidden');
    arrive();
    render(<UnreadSync />);

    await advance(INTERVAL_MS);
    ring();
    expect(net.fetch).not.toHaveBeenCalled();

    visibility('visible');
    act(() => {
        document.dispatchEvent(new Event('visibilitychange'));
    });
    expect(net.fetch).toHaveBeenCalledTimes(1);

    ring();
    expect(net.fetch).toHaveBeenCalledTimes(2);
    expect(net.init(0).signal?.aborted).toBe(true);
    expect(net.init(1).signal?.aborted).toBe(false);
});

test('a refused or dropped read changes nothing', async () => {
    const net = wire();
    arrive({ unread: counts(1) });
    render(<UnreadSync />);
    vi.clearAllMocks();

    ring();
    await net.settle(0, answer({ unread: counts(9), talkNavRooms: null }, 500));
    ring();
    await net.drop(1);

    expect(inertia.replaceProp).not.toHaveBeenCalled();
    expect(pushLib.setAppBadge).not.toHaveBeenCalled();
});

test('the worker asking for a refresh is read for even on a hidden tab and answered on its port; other messages are not', () => {
    const net = wire();
    const listeners: Record<string, (event: MessageEvent) => void> = {};
    const removeEventListener = vi.fn();
    Object.defineProperty(navigator, 'serviceWorker', {
        configurable: true,
        value: {
            addEventListener: (type: string, listener: (event: MessageEvent) => void) => {
                listeners[type] = listener;
            },
            removeEventListener,
        },
    });
    visibility('hidden');
    arrive();
    const { unmount } = render(<UnreadSync />);
    const port = { postMessage: vi.fn() };

    act(() => {
        listeners.message?.({ data: { type: 'open', url: '/x' }, ports: [port] } as unknown as MessageEvent);
    });
    expect(net.fetch).not.toHaveBeenCalled();
    expect(port.postMessage).not.toHaveBeenCalled();

    act(() => {
        listeners.message?.({ data: { type: 'refresh-unread' }, ports: [port] } as unknown as MessageEvent);
    });
    expect(net.fetch).toHaveBeenCalledTimes(1);
    expect(port.postMessage).toHaveBeenCalledWith({ type: 'ack' });

    unmount();
    expect(removeEventListener).toHaveBeenCalledWith('message', listeners.message);
});

test('unmounting stops the clock and lets the read out go', async () => {
    vi.useFakeTimers();
    const net = wire();
    arrive();
    const { unmount } = render(<UnreadSync />);

    ring();
    unmount();
    expect(net.init(0).signal?.aborted).toBe(true);

    await advance(INTERVAL_MS);
    ring();
    expect(net.fetch).toHaveBeenCalledTimes(1);
});
