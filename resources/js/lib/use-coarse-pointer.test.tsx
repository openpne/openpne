import { act, cleanup, renderHook } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { useCoarsePointer } from './use-coarse-pointer';

const original = window.matchMedia;

afterEach(() => {
    cleanup();
    window.matchMedia = original;
});

function stub(matches: boolean) {
    const listeners = new Set<() => void>();
    const mql = {
        matches,
        addEventListener: (_: string, listener: () => void) => listeners.add(listener),
        removeEventListener: (_: string, listener: () => void) => listeners.delete(listener),
    };
    window.matchMedia = vi.fn(() => mql as unknown as MediaQueryList);

    return {
        flip: (next: boolean) => {
            mql.matches = next;
            listeners.forEach((listener) => listener());
        },
        listeners,
    };
}

test('answers the pointer query and follows it when a stylus or mouse arrives', () => {
    const media = stub(true);
    const { result, unmount } = renderHook(() => useCoarsePointer());
    expect(result.current).toBe(true);

    act(() => media.flip(false));
    expect(result.current).toBe(false);

    unmount();
    expect(media.listeners.size).toBe(0);
});

test('is false where the query cannot be asked', () => {
    // @ts-expect-error the environment without matchMedia is the one under test
    window.matchMedia = undefined;
    const { result } = renderHook(() => useCoarsePointer());

    expect(result.current).toBe(false);
});
