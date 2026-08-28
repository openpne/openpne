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
 * When the marker proves the subscription is another member's (same endpoint, different member), an
 * unconfirmed rebind sheds it on ANY non-2xx — a transient failure is no reason to keep delivering the
 * prior member's pushes. Otherwise (our own, or ownership unknown) only a definitive 4xx refusal sheds
 * it; a 408/425/429/5xx or a dropped request is the server not answering, so keep the subscription and
 * retry on the next navigation — a transient outage must not unsubscribe a member's own device. Never
 * registers a worker — Classic only reconciles or invalidates a subscription that already exists.
 *
 * Also the Classic receiver of the worker's answer to a notification tap: an `open` message naming
 * the destination (public/sw.js says why the worker never navigates). No router here, so the page
 * goes there whole.
 */
(function () {
    'use strict';

    if (!('serviceWorker' in navigator) || !window.Notification || Notification.permission !== 'granted') {
        return;
    }

    navigator.serviceWorker.addEventListener('message', function (event) {
        if (event.data && event.data.type === 'open' && typeof event.data.url === 'string') {
            if (event.ports[0]) {
                event.ports[0].postMessage({ type: 'ack' });
            }
            window.location.assign(event.data.url);
        }
    });

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
    var DEFINITIVE_REFUSAL = [400, 401, 403, 404, 419, 422];
    function reconcileOutcome(status, knownForeign) {
        if (status >= 200 && status < 300) {
            return 'confirm';
        }
        if (knownForeign) {
            return 'unsubscribe'; // a subscription we know is another member's is shed on any non-2xx
        }
        return DEFINITIVE_REFUSAL.indexOf(status) !== -1 ? 'unsubscribe' : 'keep';
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
                var marker = readBinding();
                if (!shouldReconcile(marker, sub.endpoint, Date.now())) {
                    return; // confirmed same-member binding: zero requests per load
                }
                // Marker proves prior ownership: a matching endpoint under a different member is a
                // subscription we know is someone else's, so an unconfirmed rebind must shed it.
                var knownForeign = !!marker && marker.endpoint === sub.endpoint && marker.memberId !== memberId;
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
                        var outcome = reconcileOutcome(res.status, knownForeign);
                        if (outcome === 'confirm') {
                            writeBinding({ endpoint: sub.endpoint, memberId: memberId, at: Date.now() });
                            return;
                        }
                        if (outcome === 'keep') {
                            return; // transient: marker unwritten, so the next navigation retries
                        }
                        // Refused (or known-foreign): invalidate the endpoint.
                        clearBinding();
                        return sub.unsubscribe().catch(function () {});
                    })
                    .catch(function () {
                        // No answer. Keep our own/unknown subscription; a known-foreign one must still
                        // be shed, since keeping it would leak the prior member's pushes.
                        if (knownForeign) {
                            clearBinding();
                            sub.unsubscribe().catch(function () {});
                        }
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
