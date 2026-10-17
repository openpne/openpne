import { router } from '@inertiajs/react';
import { useSyncExternalStore } from 'react';

export interface HttpFailure {
    status: number;
}

let current: HttpFailure | null = null;
const listeners = new Set<() => void>();

function emit(): void {
    listeners.forEach((listener) => listener());
}

export function dismissHttpFailure(): void {
    current = null;
    emit();
}

export function useHttpFailure(): HttpFailure | null {
    return useSyncExternalStore(
        (listener) => {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
        () => current,
        () => null,
    );
}

/**
 * Inertia shows any non-Inertia reply (a maintenance page, an uncaught 500, an expired session) as
 * a raw overlay in every environment; cancelling the event keeps that overlay away and raises our
 * dialog instead, with the page the member was on left as it was.
 */
export function installHttpExceptionHandling(): () => void {
    return router.on('httpException', (event) => {
        event.preventDefault();
        current = { status: event.detail.response.status };
        emit();
    });
}
