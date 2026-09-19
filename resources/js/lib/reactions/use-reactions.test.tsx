import { act, cleanup, renderHook } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { useReactions, type ReactionEndpoints } from './use-reactions';

vi.mock('@/lib/csrf', () => ({ xsrfHeader: () => ({}) }));

const endpoints: ReactionEndpoints = { add: (id) => `/add/${id}`, remove: (id) => `/remove/${id}`, reactors: (id) => `/who/${id}` };

function answer(body: unknown, ok = true) {
    return { ok, json: () => Promise.resolve(body) } as Response;
}

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

test('a tap is drawn at once and replaced by the row the write answers with', async () => {
    let resolve: (value: Response) => void = () => {};
    vi.stubGlobal(
        'fetch',
        vi.fn(() => new Promise<Response>((r) => (resolve = r))),
    );
    const { result } = renderHook(() => useReactions(endpoints, 'page-1'));

    act(() => result.current.toggle(7, '👍', false));
    expect(result.current.chips(7, [])).toEqual([{ emoji: '👍', count: 1, mine: true }]);

    // The answer carries what someone else added meanwhile.
    await act(async () => {
        resolve(answer({ reactions: [{ emoji: '👍', count: 2, mine: true }] }));
    });
    expect(result.current.chips(7, [])).toEqual([{ emoji: '👍', count: 2, mine: true }]);
});

test('a refusal takes only the guess away', async () => {
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(answer({}, false))));
    const rendered = [{ emoji: '👍', count: 3, mine: false }];
    const { result } = renderHook(() => useReactions(endpoints, 'page-1'));

    await act(async () => result.current.toggle(7, '👍', false));

    expect(result.current.chips(7, rendered)).toEqual(rendered);
});

test('a second tap on a chip with one out is ignored', () => {
    const fetchMock = vi.fn(() => new Promise<Response>(() => {}));
    vi.stubGlobal('fetch', fetchMock);
    const { result } = renderHook(() => useReactions(endpoints, 'page-1'));

    act(() => result.current.toggle(7, '👍', false));
    act(() => result.current.toggle(7, '👍', false));

    expect(fetchMock).toHaveBeenCalledTimes(1);
});

test('a fresh page drops what an earlier one was answered with', async () => {
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve(answer({ reactions: [{ emoji: '👍', count: 1, mine: true }] }))));
    const { result, rerender } = renderHook(({ key }) => useReactions(endpoints, key), { initialProps: { key: 'a' } });

    await act(async () => result.current.toggle(7, '👍', false));
    expect(result.current.chips(7, [])).toHaveLength(1);

    rerender({ key: 'b' });
    expect(result.current.chips(7, [])).toEqual([]);
});

test('an answer to an earlier tap is not drawn over a later one', async () => {
    const pending: Array<(value: Response) => void> = [];
    vi.stubGlobal(
        'fetch',
        vi.fn(() => new Promise<Response>((r) => pending.push(r))),
    );
    const settle = (i: number, value: Response) => {
        const resolve = pending[i];
        if (resolve === undefined) throw new Error(`no request ${i}`);
        resolve(value);
    };
    const { result } = renderHook(() => useReactions(endpoints, 'page-1'));

    act(() => result.current.toggle(7, '👍', false));
    act(() => result.current.toggle(7, '❤️', false));

    // The second write's answer lands first, then the first's, which was read before the second committed.
    await act(async () => {
        settle(1, answer({ reactions: [{ emoji: '👍', count: 1, mine: true }, { emoji: '❤️', count: 1, mine: true }] }));
    });
    await act(async () => {
        settle(0, answer({ reactions: [{ emoji: '👍', count: 1, mine: true }] }));
    });

    expect(result.current.chips(7, [])).toEqual([
        { emoji: '👍', count: 1, mine: true },
        { emoji: '❤️', count: 1, mine: true },
    ]);
});

test('a fresh page closes the reactor list it was opened on', () => {
    const { result, rerender } = renderHook(({ key }) => useReactions(endpoints, key), { initialProps: { key: 'a' } });

    act(() => result.current.showReactors(7));
    expect(result.current.reactorsFor).toBe(7);

    rerender({ key: 'b' });
    expect(result.current.reactorsFor).toBeNull();
});
