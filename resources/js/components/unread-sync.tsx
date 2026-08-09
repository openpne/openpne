import { router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { clearAppBadge, reconcileSubscription, resumeRegistration, setAppBadge } from '@/lib/push';
import type { PageProps, UnreadCounts } from '@/types';

/** How often a visible tab re-reads the counts. */
const INTERVAL_MS = 60_000;

/** Mirror the OS app-icon badge to the notifications count (both calls feature-detected inside). */
function writeBadge(count: number): void {
    if (count > 0) {
        setAppBadge(count);
    } else {
        clearAppBadge();
    }
}

/**
 * Keeps the shared `unread` counts live while a tab sits open, and mirrors the notifications count to
 * the OS app-icon badge. Non-visual; mounted once in the app shell, which persists across SPA
 * navigations (auth pages have no layout, so nothing polls there).
 *
 * Refreshes are pushed back into the shared prop, so every consumer — the badges, and the title
 * callback reading `page.props.unread` (app.tsx) — re-renders from the one source it already reads.
 * Not Inertia's `usePoll`: that still fires on a hidden tab (every tenth interval) and does not
 * refresh on return to the tab, which is the moment a stale badge is seen.
 */
export function UnreadSync() {
    const { unread, push, auth } = usePage<PageProps>().props;
    const signedIn = unread != null;
    const pushConfigured = push != null;
    const memberId = auth.user?.id ?? null;

    // App-icon badge on every foreground change: mark-all, opening the feed and navigation all refresh
    // this prop. The fetch path below covers the other case — an unchanged value that a background
    // push left a stale badge under, which this keyed effect would not re-fire for.
    useEffect(() => {
        if (signedIn) {
            writeBadge(unread?.notifications ?? 0);
        }
    }, [signedIn, unread?.notifications]);

    // A device's push ownership follows the member signed in now: re-register the worker (an opted-in
    // browser re-fetches an updated /sw.js) then rebind any existing subscription to this member.
    // reconcileSubscription POSTs only on an ownership transition (memberId change / new device) and
    // fails closed on a rejected rebind; it never subscribes a fresh browser. This is the Modern half;
    // the Classic surface runs the same rebind from its header (push-reconcile.js), so an account
    // switch on either surface is covered. signedIn ⇒ auth.user is non-null, so memberId is set here.
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

        // The authoritative counts read. Also overwrites the badge from the fresh value: a background
        // push may have written a stale one, and if the count is unchanged the keyed effect above will
        // not re-fire to correct it.
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

                    return res.json() as Promise<UnreadCounts>;
                })
                .then((counts) => {
                    router.replaceProp('unread', counts);
                    writeBadge(counts.notifications);
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

        // The worker hands the badge's source of truth to a live tab. Fetch unconditionally — it asked,
        // and the tab may have flipped hidden since the worker's matchAll — then ACK on the transferred
        // port so the worker knows a handler took responsibility and skips its own fallback badge write.
        const sw = 'serviceWorker' in navigator ? navigator.serviceWorker : null;
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
            sw?.removeEventListener('message', onMessage);
            inFlight?.abort();
        };
    }, [signedIn]);

    return null;
}
