import { act, renderHook } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { useRowActionsHint } from './use-row-actions-hint';

const inertia = vi.hoisted(() => ({ page: { props: { rowActionsHint: 'shown' as 'shown' | 'dismissed' | null } } }));
vi.mock('@inertiajs/react', () => ({ usePage: () => inertia.page }));

afterEach(() => {
    vi.unstubAllGlobals();
});

test('shown until dismissed; the dismissal is written once, without a body, and never again', () => {
    const fetch = vi.fn(() => Promise.resolve(new Response(null, { status: 204 })));
    vi.stubGlobal('fetch', fetch);
    inertia.page.props.rowActionsHint = 'shown';
    const { result } = renderHook(() => useRowActionsHint());

    expect(result.current.visible).toBe(true);
    act(() => result.current.dismiss());
    act(() => result.current.dismiss());

    expect(result.current.visible).toBe(false);
    expect(fetch).toHaveBeenCalledTimes(1);
    expect(fetch).toHaveBeenCalledWith('/member/config/row-actions-hint', expect.objectContaining({ method: 'POST', credentials: 'same-origin' }));
    expect((fetch.mock.calls[0] as unknown as [string, RequestInit])[1].body).toBeUndefined();
});

test.each([['dismissed'], [null]] as const)('with %s from the page there is nothing to show and nothing to write', (state) => {
    const fetch = vi.fn();
    vi.stubGlobal('fetch', fetch);
    inertia.page.props.rowActionsHint = state;
    const { result } = renderHook(() => useRowActionsHint());

    expect(result.current.visible).toBe(false);
    act(() => result.current.dismiss());
    expect(fetch).not.toHaveBeenCalled();
});
