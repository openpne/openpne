/**
 * Classic-surface push ownership reconcile — the Classic half of push.ts's reconcileSubscription, so
 * the shared-browser account-switch leak (A subscribes on Modern, logs out; B logs in on Classic) is
 * closed on Classic too. A push row belongs to whoever last POSTed its endpoint, so re-POSTing rebinds
 * it to the member signed in now. Fail closed: an unconfirmed rebind (non-2xx or a network error)
 * unsubscribes locally, so the prior owner's push can no longer land. Never registers a worker —
 * Classic only reconciles or invalidates a subscription that already exists, it never installs push.
 */
(function () {
    'use strict';

    if (!('serviceWorker' in navigator) || !window.Notification || Notification.permission !== 'granted') {
        return;
    }

    try {
        navigator.serviceWorker.getRegistration()
            .then(function (registration) {
                return registration ? registration.pushManager.getSubscription() : null;
            })
            .then(function (sub) {
                if (!sub) {
                    return; // no existing subscription: never subscribe a fresh browser
                }
                var meta = document.querySelector('meta[name="csrf-token"]');
                var json = sub.toJSON();
                fetch('/push/subscriptions', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': meta ? meta.getAttribute('content') : '',
                    },
                    body: JSON.stringify({ endpoint: json.endpoint, keys: json.keys }),
                })
                    .then(function (res) {
                        if (!res.ok) {
                            throw new Error('rebind rejected'); // a non-2xx is unconfirmed, same as a throw
                        }
                    })
                    .catch(function () {
                        // Rebind unconfirmed => invalidate the endpoint so the prior owner's push dies.
                        sub.unsubscribe().catch(function () {});
                    });
            })
            .catch(function () {
                // getRegistration / getSubscription failed: nothing to reconcile.
            });
    } catch {
        // Nothing above is expected to throw synchronously; swallow it if it does.
    }
}());
