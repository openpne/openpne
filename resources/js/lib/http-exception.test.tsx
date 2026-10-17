import { act, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { dismissHttpFailure, installHttpExceptionHandling, useHttpFailure } from '@/lib/http-exception';

function fire(status: number): CustomEvent {
    const event = new CustomEvent('inertia:httpException', { cancelable: true, detail: { response: { status, data: '', headers: {} } } });
    document.dispatchEvent(event);

    return event;
}

describe('installHttpExceptionHandling', () => {
    let off: (() => void) | null = null;

    afterEach(() => {
        off?.();
        off = null;
        dismissHttpFailure();
    });

    it('cancels the raw overlay and records the status', () => {
        off = installHttpExceptionHandling();
        const { result } = renderHook(() => useHttpFailure());

        let event: CustomEvent | null = null;
        act(() => {
            event = fire(503);
        });

        expect(event!.defaultPrevented).toBe(true);
        expect(result.current).toEqual({ status: 503 });
    });

    it('lets the overlay through once uninstalled', () => {
        off = installHttpExceptionHandling();
        off();
        off = null;

        expect(fire(500).defaultPrevented).toBe(false);
    });

    it('clears on dismiss', () => {
        off = installHttpExceptionHandling();
        const { result } = renderHook(() => useHttpFailure());

        act(() => {
            fire(419);
        });
        act(() => {
            dismissHttpFailure();
        });

        expect(result.current).toBeNull();
    });
});
