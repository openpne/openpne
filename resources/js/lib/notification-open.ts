/**
 * Two messages from the worker (public/sw.js): `open-offer`, answered on the port it carries, then
 * `open` with the URL. The listener must be registered before DOMContentLoaded, from the entry
 * module's top level, because the container drops a worker's messages nobody was listening for.
 */
export interface NotificationOpen {
    handleMessage(event: MessageEvent): void;
    /** The router can take a visit now; a destination that arrived earlier is followed. */
    ready(): void;
}

export function createNotificationOpen(visit: (url: string) => void): NotificationOpen {
    let pending: string | null = null;
    let ready = false;

    return {
        handleMessage(event) {
            const data: unknown = event.data;
            if (!isRecord(data)) {
                return;
            }
            if (data.type === 'open-offer') {
                event.ports[0]?.postMessage({ type: 'ack' });
            } else if (data.type === 'open' && typeof data.url === 'string') {
                if (ready) {
                    visit(data.url);
                } else {
                    pending = data.url;
                }
            }
        },
        ready() {
            ready = true;
            if (pending !== null) {
                const url = pending;
                pending = null;
                visit(url);
            }
        },
    };
}

export function installNotificationOpen(visit: (url: string) => void): NotificationOpen {
    const open = createNotificationOpen(visit);
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', open.handleMessage);
    }

    return open;
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}
