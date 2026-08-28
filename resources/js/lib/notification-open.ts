/**
 * The page's half of a notification tap (see public/sw.js): the worker offers the tap to every open
 * window and hands the destination to the first that answers. Two messages: `open-offer`, answered
 * on the port it carries, then `open` with the URL, which the page follows once the router can take
 * a visit.
 *
 * The listener must be registered before DOMContentLoaded — from the entry module's top level (module
 * scripts run before that event), never from a component effect. The container holds a worker's
 * messages only until DOMContentLoaded and drops later ones nobody listens for, and a page the worker
 * has just opened is offered as soon as it commits, so a listener added after load never hears it.
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
