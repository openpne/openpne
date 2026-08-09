// Web push service worker, served from the site root so its scope is '/' and it controls every page
// (a worker under /js/ could only control /js/*). Three responsibilities and nothing else: keep
// itself current (install/activate), turn a push message into a notification, and route a
// notification click to the feed. No fetch handler and no caching — this is not an offline shell, so
// it stays off the request path of an app it has no reason to serve.

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let data = {};
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

    event.waitUntil(
        Promise.all([
            self.registration.showNotification(data.title || 'OpenPNE', options),
            self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
                // A visible tab holds the authoritative unread count, so let it re-sync the badge from
                // its own fetch. Writing this push's (possibly late, possibly stale) count over the
                // foreground's true value is exactly the race we avoid.
                let anyVisible = false;
                for (const client of clients) {
                    if (client.visibilityState === 'visible') {
                        anyVisible = true;
                        client.postMessage({ type: 'refresh-unread' });
                    }
                }
                if (!anyVisible && 'setAppBadge' in navigator && typeof data.unreadCount === 'number') {
                    navigator.setAppBadge(data.unreadCount);
                }
            }),
        ]).catch(() => {}),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    if ('clearAppBadge' in navigator) {
        try {
            navigator.clearAppBadge();
        } catch {
            // Best effort; a browser without the Badging API just keeps whatever badge it had.
        }
    }

    const url = (event.notification.data && event.notification.data.url) || '/notifications';
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            for (const client of clients) {
                if ('focus' in client) {
                    return client.focus().then((focused) => ('navigate' in focused ? focused.navigate(url) : undefined));
                }
            }
            return self.clients.openWindow(url);
        }),
    );
});
