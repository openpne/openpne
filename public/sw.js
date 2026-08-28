// Web push service worker, served from the site root so its scope is '/' and it controls every page
// (a worker under /js/ could only control /js/*). Three responsibilities and nothing else: keep
// itself current (install/activate), turn a push message into a notification, and route a
// notification click to the feed. No fetch handler and no caching — this is not an offline shell, so
// it stays off the request path of an app it has no reason to serve.

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let data;
    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = {};
    }

    const options = {
        body: data.body,
        icon: data.icon,
        tag: data.tag || 'openpne-notifications',
        data,
    };

    // In the awaited chain so the worker stays alive until the badge is reconciled.
    event.waitUntil(
        Promise.all([
            self.registration.showNotification(data.title || 'OpenPNE', options),
            reconcileBadge(data),
        ]).catch(() => {}),
    );
});

// A visible tab holds the authoritative unread count, so let it re-sync the badge from its own fetch;
// writing this push's (possibly late, possibly stale) count over the foreground's true value is the
// race we avoid. But matchAll at root scope also returns Classic and login pages, which have no
// refresh-unread handler — a bare postMessage to those drops the badge silently. So suppress the
// payload badge only when a client ACKs (proves it took the refresh); otherwise write it ourselves.
async function reconcileBadge(data) {
    const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const acked = await askVisibleClients(clients);
    if (!acked && 'setAppBadge' in navigator && typeof data.unreadCount === 'number') {
        await navigator.setAppBadge(data.unreadCount);
    }
}

// Resolves true once any visible client ACKs on the port it was handed, false if none answer within
// the timeout (no visible tab, or only handler-less Classic/login pages). No MessageChannel (old
// engine) degrades to no-ACK so the fallback badge still runs.
function askVisibleClients(clients) {
    const visible = clients.filter((client) => client.visibilityState === 'visible');
    if (visible.length === 0 || typeof MessageChannel === 'undefined') {
        return Promise.resolve(false);
    }
    return new Promise((resolve) => {
        const timer = setTimeout(() => resolve(false), 500);
        for (const client of visible) {
            const channel = new MessageChannel();
            channel.port1.onmessage = () => {
                clearTimeout(timer);
                resolve(true);
            };
            client.postMessage({ type: 'refresh-unread' }, [channel.port2]);
        }
    });
}

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || '/notifications';
    // clearAppBadge is awaited inside waitUntil so the worker is not killed mid-write; a browser
    // without the Badging API just keeps whatever badge it had.
    const badge = 'clearAppBadge' in navigator ? navigator.clearAppBadge().catch(() => {}) : Promise.resolve();
    event.waitUntil(Promise.all([badge, openInApp(url)]));
});

// The destination travels as a message and the page routes itself (lib/notification-open.ts on
// Modern, push-reconcile.js on Classic); the worker never opens it. On an iOS home-screen web app,
// openWindow() with anything but the scope root opens that URL in an embedded browser sheet over an
// app window that is left blank — an empty page with a URL bar the member cannot leave. So a window
// is only ever opened at the root.
//
// Two steps: every open window is offered the tap at once, and the first in focus order to answer is
// focused and handed the destination — login, admin and guest pages have no receiver, and one of
// those in front must not swallow the tap. A page still loading cannot answer yet (the container holds
// a worker's message only until DOMContentLoaded, and a freshly opened window is offered at commit),
// so the offer is repeated until one answers or the deadline passes. When none does, the front window
// is shown, and moved there by navigate() except on WebKit, where it did nothing observable.
const OFFER_MS = 500;
const OPEN_DEADLINE_MS = 6000;

async function openInApp(url, timing = { offerMs: OFFER_MS, deadlineMs: OPEN_DEADLINE_MS }) {
    let windows = await openWindows();
    if (windows.length === 0) {
        const opened = await self.clients.openWindow(self.registration.scope);
        windows = opened ? [opened] : [];
    }
    const deadline = Date.now() + timing.deadlineMs;
    while (windows.length > 0) {
        const taker = await firstTaker(windows, timing.offerMs);
        if (taker) {
            await taker.focus().catch(() => {});
            taker.postMessage({ type: 'open', url });
            return;
        }
        if (Date.now() >= deadline) {
            break;
        }
        windows = await openWindows();
    }
    if (windows.length === 0) {
        return;
    }
    const front = (await windows[0].focus().catch(() => null)) || windows[0];
    if (!isWebKitBrowser()) {
        await front.navigate(url).catch(() => {});
    }
}

function openWindows() {
    return self.clients.matchAll({ type: 'window', includeUncontrolled: true });
}

// The first window, in the focus order matchAll gives, whose page answers the offer within the
// timeout; null when none does (no receiver, a page still loading, or one suspended).
async function firstTaker(windows, offerMs) {
    const answers = await Promise.all(windows.map((client) => offerOpen(client, offerMs)));
    const index = answers.indexOf(true);

    return index === -1 ? null : windows[index];
}

// Resolves true once the page ACKs on the port it was handed, false if it has not within the timeout.
// Without MessageChannel the offer cannot be confirmed, so the worker assumes no one took it.
function offerOpen(client, offerMs) {
    if (typeof MessageChannel === 'undefined') {
        return Promise.resolve(false);
    }
    return new Promise((resolve) => {
        const channel = new MessageChannel();
        const settle = (taken) => {
            clearTimeout(timer);
            channel.port1.close();
            resolve(taken);
        };
        const timer = setTimeout(() => settle(false), offerMs);
        channel.port1.onmessage = () => settle(true);
        client.postMessage({ type: 'open-offer' }, [channel.port2]);
    });
}

// Safari and every iOS browser (all WebKit); Chromium and Android engines announce themselves.
function isWebKitBrowser() {
    const ua = typeof navigator !== 'undefined' ? navigator.userAgent || '' : '';

    return /AppleWebKit/.test(ua) && !/Chrome|Chromium|Android/.test(ua);
}
