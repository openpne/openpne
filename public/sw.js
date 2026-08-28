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

// The destination travels as a message and the page routes itself (unread-sync.tsx on Modern,
// push-reconcile.js on Classic); the worker never navigates. On an iOS home-screen web app,
// openWindow() with anything but the scope root opens that URL in an embedded browser sheet over an
// app window that is left blank — an empty page with a URL bar the member cannot leave — and
// WindowClient.navigate() is a no-op. A page opened here receives the message once it listens: the
// container queues it.
async function openInApp(url) {
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    let client = windows[0] || null;
    if (client) {
        if ('focus' in client) {
            client = (await client.focus().catch(() => null)) || client;
        }
    } else {
        client = await self.clients.openWindow(self.registration.scope);
    }
    if (client) {
        client.postMessage({ type: 'open', url });
    }
}
