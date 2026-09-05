import { router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { clearAppBadge, reconcileSubscription, resumeRegistration, setAppBadge } from '@/lib/push';
import { applyUnreadPayload, type UnreadPayload } from '@/lib/unread-payload';
import { UNREAD_REFRESH_EVENT } from '@/lib/unread-refresh';
import type { PageProps } from '@/types';

const INTERVAL_MS = 60_000;

/** Both calls are feature-detected inside, so callers need no guard of their own. */
function writeBadge(count: number): void {
    if (count > 0) {
        setAppBadge(count);
    } else {
        clearAppBadge();
    }
}

/**
 * Refreshes are pushed back into the shared props, so every consumer re-renders from the source it
 * already reads (docs/internals/notifications.md, "Liveness"). Not Inertia's `usePoll`: that still
 * fires on a hidden tab and does not refresh on return to it, which is the moment a stale badge is
 * seen.
 */
export function UnreadSync() {
    const { unread, push, auth } = usePage<PageProps>().props;
    const signedIn = unread != null;
    const pushConfigured = push != null;
    const memberId = auth.user?.id ?? null;

    // Every foreground change refreshes this prop; a background push that left a stale badge under an
    // unchanged value is the fetch path's case, not this effect's.
    useEffect(() => {
        if (signedIn) {
            writeBadge(unread?.notifications ?? 0);
        }
    }, [signedIn, unread?.notifications]);

    // Ownership reconciliation, the Modern half of a rule the Classic header runs too
    // (docs/internals/notifications.md, "Web push").
    useEffect(() => {
        if (pushConfigured && signedIn && memberId != null) {
            resumeRegistration();
            void reconcileSubscription(memberId);
        }
    }, [pushConfigured, signedIn, memberId]);

    useEffect(() => {
        if (!signedIn) {
            return;
        }

        let inFlight: AbortController | null = null;

        // Also overwrites the badge from the fresh value, which is what corrects a stale one when the
        // count itself has not changed.
        const fetchCounts = () => {
            inFlight?.abort();
            const controller = new AbortController();
            inFlight = controller;

            return fetch('/unread-counts', {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then((res) => {
                    if (!res.ok) {
                        throw new Error(`unread counts failed: ${res.status}`);
                    }

                    return res.json() as Promise<UnreadPayload>;
                })
                .then((payload) => {
                    applyUnreadPayload(payload, (key, value) => router.replaceProp(key, value));
                    writeBadge(payload.unread.notifications);
                })
                .catch(() => {
                    // Keep what we have and stay quiet: a refused or dropped refresh is not news to
                    // the member, and an expired session is answered by the next real navigation.
                });
        };

        const refresh = () => {
            if (document.visibilityState !== 'visible') {
                return;
            }
            void fetchCounts();
        };

        const timer = setInterval(refresh, INTERVAL_MS);
        document.addEventListener('visibilitychange', refresh);
        // A page that has just settled a count with the server; without this the badge sits on its
        // stale number until the interval comes round.
        window.addEventListener(UNREAD_REFRESH_EVENT, refresh);

        // A page restored from history is not this component's problem: the app-wide revalidation
        // (lib/revalidate-on-restore.ts) reloads it whole, and a full reload re-reads the shared
        // props these counts live in along with everything else.

        // Fetch unconditionally — the tab may have flipped hidden since the worker's matchAll — then
        // ACK on the transferred port, so the worker skips its own fallback badge write.
        const sw = 'serviceWorker' in navigator ? navigator.serviceWorker : null;
        // (A notification tap's messages are answered from the entry module, lib/notification-open.ts —
        // a listener added here, after load, would never hear them.)
        const onMessage = (event: MessageEvent) => {
            if (event.data?.type === 'refresh-unread') {
                void fetchCounts();
                event.ports[0]?.postMessage({ type: 'ack' });
            }
        };
        sw?.addEventListener('message', onMessage);

        return () => {
            clearInterval(timer);
            document.removeEventListener('visibilitychange', refresh);
            window.removeEventListener(UNREAD_REFRESH_EVENT, refresh);
            sw?.removeEventListener('message', onMessage);
            inFlight?.abort();
        };
    }, [signedIn]);

    return null;
}
