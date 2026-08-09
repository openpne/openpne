/**
 * Classic-surface push ownership reconcile — the Classic half of push.ts's reconcileSubscription, so
 * the shared-browser account-switch leak (A subscribes on Modern, logs out; B logs in on Classic) is
 * closed on Classic too. A push row belongs to whoever last POSTed its endpoint, so re-POSTing rebinds
 * it to the member signed in now.
 *
 * Classic is not an SPA, so this runs on every full-page load. It must not re-POST an already-confirmed
 * same-member binding: the store route carries throttle:30,1, and a per-load POST would 429 within a
 * minute of browsing and, failing closed on that 429, unsubscribe the member's own device. So the POST
 * fires only on an ownership transition (no marker, a different member/endpoint, or a stale marker),
 * mirroring shouldReconcile in resources/js/lib/push.ts — push.test.ts is the reference for this
 * predicate and for reconcileOutcome; keep the two identical.
 *
 * Fail closed only on a *refused* rebind — a definitive 4xx (reconcileOutcome): unsubscribe locally so
 * the prior owner's push can no longer land. A 429, a 5xx or a dropped request is the server not
 * answering rather than refusing, so keep the subscription and retry on the next navigation — a
 * transient outage must not unsubscribe a member's own device. Never registers a worker — Classic only
 * reconciles or invalidates a subscription that already exists.
 */
(function () {
    'use strict';

    if (!('serviceWorker' in navigator) || !window.Notification || Notification.permission !== 'granted') {
        return;
    }

    var BOUND_KEY = 'openpne-push-bound';
    var TTL_MS = 12 * 60 * 60 * 1000;

    var scriptTag = document.querySelector('script[data-push-member-id]');
    var memberId = scriptTag ? parseInt(scriptTag.getAttribute('data-push-member-id'), 10) : NaN;
    if (Number.isNaN(memberId)) {
        return; // no member id emitted (guest / unconfigured): nothing to reconcile ownership to
    }

    // Mirrors shouldReconcile in resources/js/lib/push.ts (push.test.ts pins the cases).
    function shouldReconcile(marker, endpoint, now) {
        if (!marker) {
            return true;
        }
        return !(marker.endpoint === endpoint && marker.memberId === memberId && now - marker.at < TTL_MS);
    }

    // Mirrors reconcileOutcome in resources/js/lib/push.ts (push.test.ts pins the cases).
    function reconcileOutcome(status) {
        if (status >= 200 && status < 300) {
            return 'confirm';
        }
        if (status === 429 || status >= 500) {
            return 'keep';
        }
        return 'unsubscribe';
    }

    function readBinding() {
        try {
            var raw = localStorage.getItem(BOUND_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch {
            return null; // blocked storage (private mode) degrades to "no marker"
        }
    }

    function writeBinding(binding) {
        try {
            localStorage.setItem(BOUND_KEY, JSON.stringify(binding));
        } catch {
            // Blocked storage: reconcile POSTs each load, but a 429 no longer unsubscribes, so the
            // regression stays closed even without storage.
        }
    }

    function clearBinding() {
        try {
            localStorage.removeItem(BOUND_KEY);
        } catch {
            // Same degradation as writeBinding.
        }
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
                if (!shouldReconcile(readBinding(), sub.endpoint, Date.now())) {
                    return; // confirmed same-member binding: zero requests per load
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
                        var outcome = reconcileOutcome(res.status);
                        if (outcome === 'confirm') {
                            writeBinding({ endpoint: sub.endpoint, memberId: memberId, at: Date.now() });
                            return;
                        }
                        if (outcome === 'keep') {
                            return; // transient (429/5xx): marker unwritten, so the next navigation retries
                        }
                        // Definitive 4xx: the reclaim was refused, so invalidate the endpoint.
                        clearBinding();
                        return sub.unsubscribe().catch(function () {});
                    })
                    .catch(function () {
                        // A dropped request is the server not answering, not refusing — keep the
                        // subscription and let the next navigation retry, the same as a 429 or 5xx.
                    });
            })
            .catch(function () {
                // getRegistration / getSubscription failed: no subscription handle, so nothing to
                // unsubscribe — the fail-closed guarantee is scoped to a rejected rebind POST.
            });
    } catch {
        // Nothing above is expected to throw synchronously; swallow it if it does.
    }
}());
