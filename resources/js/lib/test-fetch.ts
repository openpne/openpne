import { act } from '@testing-library/react';
import { vi } from 'vitest';

type Call = [string, RequestInit];

export function answer(body: unknown = null, status = 200): Response {
    return { ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) } as Response;
}

/** Answers are handed out in call order; each stays open until the test settles or drops it. */
export function wire() {
    const pending: Array<{ resolve: (value: Response) => void; reject: (reason: unknown) => void }> = [];
    const fetch = vi.fn(() => new Promise<Response>((resolve, reject) => pending.push({ resolve, reject })));
    vi.stubGlobal('fetch', fetch);
    const request = (i: number): Call => {
        const call = fetch.mock.calls[i] as unknown as Call | undefined;
        if (call === undefined) throw new Error(`no request ${i}`);

        return call;
    };
    const waiting = (i: number) => {
        const entry = pending[i];
        if (entry === undefined) throw new Error(`no request ${i}`);

        return entry;
    };

    return {
        fetch,
        url: (i: number) => request(i)[0],
        init: (i: number) => request(i)[1],
        settle: (i: number, value: Response) =>
            act(async () => {
                waiting(i).resolve(value);
            }),
        drop: (i: number) =>
            act(async () => {
                waiting(i).reject(new TypeError('network'));
            }),
    };
}

/** Restored by `Reflect.deleteProperty(document, 'visibilityState')`. */
export function visibility(state: DocumentVisibilityState) {
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => state });
}
