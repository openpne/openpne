import type { MouseEvent } from 'react';
import { beforeEach, expect, test, vi } from 'vitest';
import { pressPrefetch } from './press-prefetch';

const inertia = vi.hoisted(() => ({
    pending: false,
    listeners: [] as [string, (event: { detail: { page: { url: string } } }) => void][],
    router: {
        prefetch: vi.fn(),
        getPrefetching: vi.fn(),
        getCached: vi.fn(),
        flush: vi.fn(),
        on: (name: string, listener: (event: { detail: { page: { url: string } } }) => void) => {
            inertia.listeners.push([name, listener]);
        },
    },
}));
const pages = vi.hoisted(() => ({ load: vi.fn(() => Promise.resolve({})) }));
vi.mock('@inertiajs/react', () => ({ router: inertia.router }));
vi.mock('@/lib/page-modules', () => ({
    pageModules: { '../pages/notifications/index.tsx': pages.load },
    pagePath: (name: string) => `../pages/${name}.tsx`,
}));

const primary = { button: 0, metaKey: false, ctrlKey: false, shiftKey: false, altKey: false } as MouseEvent;

beforeEach(() => {
    inertia.pending = false;
    inertia.router.prefetch.mockReset().mockImplementation(() => {
        inertia.pending = true;
    });
    inertia.router.getPrefetching.mockReset().mockImplementation(() => (inertia.pending ? {} : null));
    inertia.router.getCached.mockReset().mockReturnValue(null);
    pages.load.mockClear();
});

test('a touch press and the mousedown synthesized after it send one request', () => {
    const handlers = pressPrefetch('/notifications');

    handlers.onTouchStart();
    handlers.onMouseDown(primary);

    expect(inertia.router.prefetch).toHaveBeenCalledTimes(1);
    expect(inertia.router.prefetch).toHaveBeenCalledWith('/notifications', expect.anything(), { cacheFor: '2s' });
});

test('a response still cached from the press before is not fetched again', () => {
    inertia.router.getCached.mockReturnValue({});

    pressPrefetch('/notifications').onMouseDown(primary);

    expect(inertia.router.prefetch).not.toHaveBeenCalled();
});

test.each([{ button: 1 }, { button: 2 }, { metaKey: true }, { ctrlKey: true }, { shiftKey: true }, { altKey: true }])(
    'a press that will not navigate here sends nothing: %o',
    (override) => {
        pressPrefetch('/notifications').onMouseDown({ ...primary, ...override } as MouseEvent);

        expect(inertia.router.prefetch).not.toHaveBeenCalled();
    },
);

test('the page module named by the response is loaded with it, whichever shape the body arrives in', () => {
    pressPrefetch('/notifications').onTouchStart();
    const { onPrefetched } = inertia.router.prefetch.mock.calls[0]?.[1] as { onPrefetched: (response: unknown) => void };

    onPrefetched({ data: JSON.stringify({ component: 'notifications/index' }) });
    onPrefetched({ data: { component: 'notifications/index' } });
    onPrefetched({ data: { component: 'not/a-page' } });
    onPrefetched({});

    expect(pages.load).toHaveBeenCalledTimes(2);
});

test.each(['', '<html><body>502 Bad Gateway</body></html>', 'null', '[]', '{"component":7}'])('a body that is not a page is ignored rather than thrown on: %j', (data) => {
    pressPrefetch('/notifications').onTouchStart();
    const { onPrefetched } = inertia.router.prefetch.mock.calls[0]?.[1] as { onPrefetched: (response: unknown) => void };

    expect(() => onPrefetched({ data })).not.toThrow();
    expect(pages.load).not.toHaveBeenCalled();
});

test('a completed navigation drops the entry for the page it reached, and the listener is installed once', () => {
    pressPrefetch('/notifications').onTouchStart();
    inertia.pending = false;
    pressPrefetch('/timeline').onTouchStart();

    const navigate = inertia.listeners.filter(([name]) => name === 'navigate');
    expect(navigate).toHaveLength(1);
    navigate[0]?.[1]({ detail: { page: { url: '/notifications' } } });
    expect(inertia.router.flush).toHaveBeenCalledTimes(1);
    expect(inertia.router.flush).toHaveBeenCalledWith('/notifications');
});
